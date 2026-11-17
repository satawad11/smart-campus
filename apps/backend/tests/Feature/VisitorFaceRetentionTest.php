<?php

namespace Tests\Feature;

use App\Enums\FaceProfileStatus;
use App\Enums\PersonType;
use App\Enums\VisitStatus;
use App\Models\AuditLog;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use App\Models\Person;
use App\Models\SystemSetting;
use App\Models\Visit;
use App\Models\VisitorProfile;
use App\Services\System\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VisitorFaceRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('face_samples');
        $this->seed(SystemSettingSeeder::class);
    }

    /** @return array{Person, FaceProfile, FaceSample} */
    private function visitorWithFace(VisitStatus $status, int $daysAgo, ?int $retentionDays = null): array
    {
        if ($retentionDays !== null) {
            app(SystemSettingService::class)->updateGroup('RETENTION', ['visitor.face_retention_days' => $retentionDays]);
        }
        $person = Person::factory()->create(['person_type' => PersonType::VISITOR]);
        VisitorProfile::factory()->for($person)->create();
        Visit::factory()->create([
            'visitor_person_id' => $person->id,
            'status' => $status,
            'valid_from' => now()->subDays($daysAgo + 2),
            'valid_until' => now()->subDays($daysAgo + 1),
            'checked_in_at' => $status === VisitStatus::CHECKED_OUT ? now()->subDays($daysAgo + 2) : null,
            'checked_out_at' => $status === VisitStatus::CHECKED_OUT ? now()->subDays($daysAgo) : null,
            'updated_at' => now()->subDays($daysAgo),
        ]);
        $profile = FaceProfile::factory()->create(['person_id' => $person->id, 'status' => FaceProfileStatus::ACTIVE]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE face_profiles SET embedding = CAST(? AS vector) WHERE id = ?', ['['.implode(',', array_fill(0, 512, '0.01')).']', $profile->id]);
        } else {
            DB::table('face_profiles')->where('id', $profile->id)->update(['embedding' => '[0.01]']);
        }
        $sample = FaceSample::factory()->create(['face_profile_id' => $profile->id]);
        Storage::disk('face_samples')->put($sample->image_path, 'private-face-image');

        return [$person, $profile, $sample];
    }

    private function embeddingOf(FaceProfile $profile): mixed
    {
        return DB::table('face_profiles')->where('id', $profile->id)->value('embedding');
    }

    public function test_retention_command_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($scheduled) => str_contains((string) $scheduled->command, 'visitors:retain-faces'));

        $this->assertNotNull($event);
        $this->assertSame('30 2 * * *', $event->expression);
    }

    public function test_scheduled_command_revokes_and_erases_old_visitor_face_data_with_audit(): void
    {
        [$person, $profile, $sample] = $this->visitorWithFace(VisitStatus::CHECKED_OUT, 31);

        $this->artisan('visitors:retain-faces')->assertSuccessful();

        $profile->refresh();
        $this->assertSame(FaceProfileStatus::REVOKED, $profile->status);
        $this->assertSame('Visitor face retention elapsed (30 days)', $profile->revocation_reason);
        $this->assertNotNull($profile->revoked_at);
        $this->assertNull($this->embeddingOf($profile));
        $this->assertNotNull($profile->biometrics_purged_at);
        Storage::disk('face_samples')->assertMissing($sample->image_path);
        $this->assertNotNull($sample->fresh()->purged_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'FACE_REVOKED', 'entity_id' => $person->uuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'FACE_BIOMETRICS_PURGED', 'entity_id' => $person->uuid]);
    }

    public function test_retention_setting_controls_age_and_latest_ended_visit(): void
    {
        [$eligible] = $this->visitorWithFace(VisitStatus::CANCELLED, 8, 7);
        [$recent, $recentProfile] = $this->visitorWithFace(VisitStatus::CHECKED_OUT, 6);
        Visit::factory()->create([
            'visitor_person_id' => $recent->id,
            'status' => VisitStatus::CANCELLED,
            'valid_from' => now()->subDays(2),
            'valid_until' => now()->subDay(),
            'updated_at' => now()->subDays(2),
        ]);

        $this->artisan('visitors:retain-faces')->assertSuccessful();

        $this->assertSame(FaceProfileStatus::REVOKED, $eligible->faceProfile()->firstOrFail()->fresh()->status);
        $this->assertSame(FaceProfileStatus::ACTIVE, $recentProfile->fresh()->status);
    }

    public function test_open_visit_prevents_retention_and_feature_flag_does_not_disable_privacy_cleanup(): void
    {
        [$person, $profile] = $this->visitorWithFace(VisitStatus::CHECKED_OUT, 45);
        Visit::factory()->create([
            'visitor_person_id' => $person->id,
            'status' => VisitStatus::CHECKED_IN,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addHour(),
        ]);
        SystemSetting::where('key', 'feature.visitor')->update(['value' => 'false']);

        $this->artisan('visitors:retain-faces')->assertSuccessful();

        $this->assertSame(FaceProfileStatus::ACTIVE, $profile->fresh()->status);
        $this->assertNotNull($this->embeddingOf($profile));
    }

    public function test_privacy_cleanup_continues_while_visitor_feature_is_disabled(): void
    {
        [$person, $profile] = $this->visitorWithFace(VisitStatus::CHECKED_OUT, 45);
        SystemSetting::where('key', 'feature.visitor')->update(['value' => 'false']);

        $this->artisan('visitors:retain-faces')->assertSuccessful();

        $this->assertSame(FaceProfileStatus::REVOKED, $profile->fresh()->status);
        $this->assertNull($this->embeddingOf($profile));
        $this->assertDatabaseHas('audit_logs', ['action' => 'FACE_BIOMETRICS_PURGED', 'entity_id' => $person->uuid]);
    }

    public function test_setting_must_be_positive_and_invalid_value_fails_closed(): void
    {
        $setting = SystemSetting::where('key', 'visitor.face_retention_days')->firstOrFail();
        $setting->update(['value' => '0']);
        Cache::forget('system_setting:visitor.face_retention_days');
        [$person, $profile] = $this->visitorWithFace(VisitStatus::CHECKED_OUT, 90);

        $this->artisan('visitors:retain-faces')->assertFailed();

        $this->assertSame(FaceProfileStatus::ACTIVE, $profile->fresh()->status);
        $this->assertNotNull($this->embeddingOf($profile));
        $this->assertSame(0, AuditLog::where('action', 'FACE_REVOKED')->count());
        $this->expectException(ValidationException::class);
        app(SystemSettingService::class)->updateGroup('RETENTION', ['visitor.face_retention_days' => 0]);
    }
}
