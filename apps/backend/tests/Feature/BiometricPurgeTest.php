<?php

namespace Tests\Feature;

use App\Enums\FaceProfileStatus;
use App\Exceptions\Face\FaceLifecycleException;
use App\Models\AuditLog;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Person;
use App\Services\Face\BiometricPurgeService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * PDPA G-1: irreversible, audited erasure of a revoked person's biometric
 * data (embeddings + sample images), keeping rows for history.
 */
class BiometricPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('face_samples');
    }

    private function setEmbedding(FaceProfile $profile): void
    {
        $vector = '['.implode(',', array_fill(0, 512, '0.01')).']';
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE face_profiles SET embedding = CAST(? AS vector) WHERE id = ?', [$vector, $profile->id]);
        } else {
            DB::table('face_profiles')->where('id', $profile->id)->update(['embedding' => $vector]);
        }
    }

    /**
     * @return array{0: Person, 1: FaceProfile, 2: list<FaceSample>}
     */
    private function enrolledPerson(FaceProfileStatus $status = FaceProfileStatus::REVOKED): array
    {
        $person = Person::factory()->create();
        $profile = FaceProfile::factory()->create(['person_id' => $person->id, 'status' => $status]);
        $this->setEmbedding($profile);
        $samples = FaceSample::factory()->count(2)->create(['face_profile_id' => $profile->id])->all();
        foreach ($samples as $sample) {
            Storage::disk('face_samples')->put($sample->image_path, 'jpeg-bytes');
        }

        return [$person, $profile, $samples];
    }

    private function embeddingOf(FaceProfile $profile): mixed
    {
        return DB::table('face_profiles')->where('id', $profile->id)->value('embedding');
    }

    public function test_purge_erases_embedding_and_images_but_keeps_rows(): void
    {
        [$person, $profile, $samples] = $this->enrolledPerson();

        $result = app(BiometricPurgeService::class)->purge($person, 'DSR-2026-001');

        $this->assertSame(['profiles' => 1, 'embeddings_cleared' => 1, 'samples_deleted' => 2, 'samples_failed' => []], $result);
        $this->assertNull($this->embeddingOf($profile));
        $this->assertNotNull($profile->fresh()->biometrics_purged_at);
        foreach ($samples as $sample) {
            Storage::disk('face_samples')->assertMissing($sample->image_path);
            $this->assertNotNull($sample->fresh()->purged_at);
            $this->assertNotNull($sample->fresh()->checksum); // integrity metadata kept
        }
        $this->assertDatabaseCount('face_samples', 2);
        $this->assertDatabaseCount('face_profiles', 1);
    }

    public function test_purge_is_audited_without_biometric_data(): void
    {
        [$person] = $this->enrolledPerson();

        app(BiometricPurgeService::class)->purge($person, 'DSR-2026-002');

        $log = AuditLog::where('action', 'FACE_BIOMETRICS_PURGED')->sole();
        $this->assertSame($person->uuid, $log->entity_id);
        $this->assertSame('DSR-2026-002', $log->metadata['reason']);
        $this->assertSame(2, $log->new_values['samples_deleted']);
        $this->assertStringNotContainsString('0.01', json_encode([$log->new_values, $log->metadata]));
    }

    public function test_purge_refuses_while_any_profile_is_not_revoked(): void
    {
        [$person, $profile, $samples] = $this->enrolledPerson(FaceProfileStatus::ACTIVE);

        try {
            app(BiometricPurgeService::class)->purge($person, 'DSR');
            $this->fail('Expected BIOMETRIC_PURGE_PROFILE_NOT_REVOKED');
        } catch (FaceLifecycleException $e) {
            $this->assertSame('BIOMETRIC_PURGE_PROFILE_NOT_REVOKED', $e->errorCode);
        }

        $this->assertNotNull($this->embeddingOf($profile));
        Storage::disk('face_samples')->assertExists($samples[0]->image_path);
        $this->assertSame(0, AuditLog::where('action', 'FACE_BIOMETRICS_PURGED')->count());
    }

    public function test_purge_requires_a_reason(): void
    {
        [$person, $profile] = $this->enrolledPerson();

        $this->expectException(FaceLifecycleException::class);
        try {
            app(BiometricPurgeService::class)->purge($person, '   ');
        } finally {
            $this->assertNotNull($this->embeddingOf($profile));
        }
    }

    public function test_purge_is_idempotent(): void
    {
        [$person] = $this->enrolledPerson();
        $purger = app(BiometricPurgeService::class);

        $purger->purge($person, 'DSR');
        $second = $purger->purge($person, 'DSR');

        $this->assertSame(0, $second['embeddings_cleared']);
        $this->assertSame(0, $second['samples_deleted']);
        $this->assertSame([], $second['samples_failed']);
    }

    public function test_failed_object_deletion_leaves_that_sample_unpurged_for_a_rerun(): void
    {
        [$person, $profile, $samples] = $this->enrolledPerson();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::shouldReceive('disk')->with('face_samples')->andReturn($disk);

        $result = app(BiometricPurgeService::class)->purge($person, 'DSR');

        $this->assertCount(2, $result['samples_failed']);
        $this->assertNull($samples[0]->fresh()->purged_at);
        $this->assertNull($this->embeddingOf($profile)); // the embedding is still cleared
    }

    public function test_command_dry_run_changes_nothing(): void
    {
        [$person, $profile, $samples] = $this->enrolledPerson();

        $this->artisan('face:purge', ['person_uuid' => $person->uuid, '--dry-run' => true])->assertSuccessful();

        $this->assertNotNull($this->embeddingOf($profile));
        Storage::disk('face_samples')->assertExists($samples[0]->image_path);
    }

    public function test_command_purges_with_force_and_reason(): void
    {
        [$person, $profile] = $this->enrolledPerson();

        $this->artisan('face:purge', ['person_uuid' => $person->uuid, '--reason' => 'DSR-9', '--force' => true])
            ->assertSuccessful();

        $this->assertNull($this->embeddingOf($profile));
    }

    public function test_command_aborts_without_confirmation(): void
    {
        [$person, $profile] = $this->enrolledPerson();

        $this->artisan('face:purge', ['person_uuid' => $person->uuid, '--reason' => 'DSR-9'])
            ->expectsConfirmation('This permanently erases the biometric data above. Continue?', 'no')
            ->assertFailed();

        $this->assertNotNull($this->embeddingOf($profile));
    }

    public function test_command_rejects_unknown_or_malformed_person(): void
    {
        $this->artisan('face:purge', ['person_uuid' => 'not-a-uuid', '--force' => true])->assertFailed();
        $this->artisan('face:purge', ['person_uuid' => '0b8f2c1e-5a3d-4c7e-9f10-2b3c4d5e6f70', '--force' => true])->assertFailed();
    }
}
