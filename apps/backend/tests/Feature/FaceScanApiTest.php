<?php

namespace Tests\Feature;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Enums\UserStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AIModel;
use App\Models\AuditLog;
use App\Models\FaceProfile;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Webcam scanning API (FACE-022): POST /faces/check-quality and POST /faces/recognize.
 */
class FaceScanApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $tester;

    protected User $enroller;

    protected User $noPerms;

    protected AIModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tester = $this->userWith('face.test');
        $this->enroller = $this->userWith('face.enroll');
        $this->noPerms = User::factory()->create(['status' => UserStatus::ACTIVE]);

        // The ACR-AI-01 model row is registered by migration; make sure it is active.
        $this->model = AIModel::query()->where('code', 'yunet-sface')->where('version', '2021dec')->first()
            ?? AIModel::factory()->create(['code' => 'yunet-sface', 'version' => '2021dec']);
        $this->model->update([
            'model_type' => AIModelType::FACE_EMBEDDING,
            'embedding_dimension' => 512,
            'distance_metric' => 'cosine',
            'status' => AIModelStatus::ACTIVE,
        ]);

        config([
            'face.recognition.match_threshold' => 0.80,
            'face.recognition.review_threshold' => 0.60,
            'face.recognition.ambiguity_margin' => 0.05,
            'face.liveness.provider' => 'ai_service',
        ]);
    }

    protected function userWith(string $permission): User
    {
        $perm = Permission::firstOrCreate(['code' => $permission], ['name' => $permission, 'module' => 'face_biometrics']);
        $role = Role::factory()->create(['code' => 'r_'.str_replace('.', '_', $permission)]);
        $role->permissions()->syncWithoutDetaching([$perm->id]);
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user->roles()->attach($role);

        return $user;
    }

    protected function frame(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'frame.jpg',
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00".str_repeat("\x00", 200)
        );
    }

    /** Unit vector along axis $axis, optionally rotated toward axis 1 to set cosine similarity. */
    protected function vector(float $cosToAxis0 = 1.0): array
    {
        $v = array_fill(0, 512, 0.0);
        $v[0] = $cosToAxis0;
        $v[1] = sqrt(max(0.0, 1 - $cosToAxis0 ** 2));

        return $v;
    }

    protected function enrolledPerson(array $vector, array $attrs = []): Person
    {
        $person = Person::factory()->create($attrs);
        $profile = FaceProfile::factory()->create([
            'person_id' => $person->id,
            'status' => FaceProfileStatus::ACTIVE,
            'recognition_model_id' => $this->model->id,
            'embedding_dimension' => 512,
            'quality_score' => 0.95,
            'enrolled_by_user_id' => $this->tester->id,
            'enrolled_at' => now(),
        ]);
        $formatted = '['.implode(',', $vector).']';
        $cast = DB::connection()->getDriverName() === 'pgsql' ? '?::vector' : '?';
        DB::statement("UPDATE face_profiles SET embedding = {$cast} WHERE id = ?", [$formatted, $profile->id]);

        return $person;
    }

    protected function fakeAi(array $probe, int $faceCount = 1, float $liveness = 0.95, int $livenessStatus = 200): void
    {
        Http::fake([
            '*/internal/v1/face/enroll' => Http::response([
                'success' => true,
                'face_count' => $faceCount,
                'model_code' => 'yunet-sface',
                'model_version' => '2021dec',
                'model_type' => 'FACE_EMBEDDING',
                'embedding_dimension' => 512,
                'embedding' => $probe,
                'quality' => [
                    'quality_score' => 0.9, 'blur_score' => 0.9, 'brightness_score' => 0.9,
                    'face_size_score' => 0.9, 'pose_score' => 0.9, 'accepted' => true, 'rejection_reasons' => [],
                ],
                'bounding_box' => ['x1' => 10.0, 'y1' => 20.0, 'x2' => 110.0, 'y2' => 140.0, 'confidence' => 0.98],
            ], 200),
            '*/internal/v1/face/liveness' => Http::response([
                'success' => true, 'liveness_score' => $liveness, 'status' => 'REAL', 'is_live' => true,
                'method' => 'MINIFASNET', 'confidence' => 0.95, 'rejection_reasons' => [], 'details' => [],
            ], $livenessStatus),
        ]);
    }

    // ---- check-quality ----------------------------------------------------

    public function test_check_quality_returns_boxes_and_metrics_without_embedding(): void
    {
        Http::fake(['*/internal/v1/face/check-quality' => Http::response([
            'success' => true, 'face_count' => 1,
            'faces' => [['x1' => 1, 'y1' => 2, 'x2' => 90, 'y2' => 100, 'confidence' => 0.97]],
            'image_width' => 640, 'image_height' => 480, 'accepted' => true, 'rejection_reasons' => [],
            'quality' => ['quality_score' => 0.9, 'blur_score' => 0.8, 'brightness_score' => 0.7,
                'face_size_score' => 0.6, 'pose_score' => 0.95, 'yaw' => 1.0, 'pitch' => 2.0, 'roll' => 0.5,
                'accepted' => true, 'rejection_reasons' => []],
            'embedding' => [0.1, 0.2], // must never be relayed
        ], 200)]);

        $res = $this->actingAs($this->enroller)->post('/api/v1/faces/check-quality', ['image' => $this->frame()]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.face_count', 1)
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.image_width', 640)
            ->assertJsonPath('data.faces.0.x2', 90)
            ->assertJsonPath('data.quality.brightness_score', 0.7);
        $this->assertStringNotContainsString('embedding', $res->getContent());
    }

    public function test_check_quality_allows_face_test_permission_too(): void
    {
        Http::fake(['*/internal/v1/face/check-quality' => Http::response([
            'success' => true, 'face_count' => 0, 'faces' => [], 'image_width' => 640, 'image_height' => 480,
            'accepted' => false, 'rejection_reasons' => ['NO_FACE'], 'quality' => null,
        ], 200)]);

        $this->actingAs($this->tester)->post('/api/v1/faces/check-quality', ['image' => $this->frame()])
            ->assertOk()
            ->assertJsonPath('data.face_count', 0)
            ->assertJsonPath('data.rejection_reasons.0', 'NO_FACE')
            ->assertJsonPath('data.quality', null);
    }

    public function test_check_quality_requires_auth_and_permission(): void
    {
        $this->postJson('/api/v1/faces/check-quality', ['image' => $this->frame()])->assertStatus(401);
        $this->actingAs($this->noPerms)->post('/api/v1/faces/check-quality', ['image' => $this->frame()], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_check_quality_validates_image(): void
    {
        $this->actingAs($this->enroller)->postJson('/api/v1/faces/check-quality', [])->assertStatus(422);
        $this->actingAs($this->enroller)->post('/api/v1/faces/check-quality', [
            'image' => $this->frame(), 'person_uuid' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_check_quality_ai_unavailable_is_503(): void
    {
        Http::fake(['*/internal/v1/face/check-quality' => Http::response([], 500)]);

        $this->actingAs($this->enroller)->post('/api/v1/faces/check-quality', ['image' => $this->frame()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE');
    }

    // ---- recognize --------------------------------------------------------

    public function test_recognize_match_returns_person_and_audits_without_vectors(): void
    {
        $person = $this->enrolledPerson($this->vector(1.0), ['first_name' => 'สมชาย', 'last_name' => 'ใจดี']);
        $this->fakeAi($this->vector(1.0));

        $res = $this->actingAs($this->tester)->post('/api/v1/faces/recognize', ['image' => $this->frame()]);

        $res->assertOk()
            ->assertJsonPath('data.classification', 'MATCH')
            ->assertJsonPath('data.matched.person.uuid', $person->uuid)
            ->assertJsonPath('data.matched.person.display_name', 'สมชาย ใจดี')
            ->assertJsonPath('data.liveness.status', 'REAL')
            ->assertJsonPath('data.model.code', 'yunet-sface')
            ->assertJsonPath('data.face.bounding_box.x2', 110);
        $this->assertStringNotContainsString('"embedding"', $res->getContent());

        $audit = AuditLog::where('action', AuditAction::FACE_RECOGNITION_TESTED->value)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($person->uuid, $audit->entity_id);
        $this->assertSame('LIVE_SCAN', $audit->metadata['mode']);
        $this->assertSame('MATCH', $audit->metadata['classification']);
        $this->assertArrayNotHasKey('embedding', $audit->metadata);

        // Identification only: no access event or decision is ever produced.
        $this->assertSame(0, AccessEvent::count());
        $this->assertSame(0, AccessDecision::count());
    }

    public function test_recognize_unknown_when_no_one_is_close(): void
    {
        $this->enrolledPerson($this->vector(1.0));
        $this->fakeAi($this->vector(0.2));

        $this->actingAs($this->tester)->post('/api/v1/faces/recognize', ['image' => $this->frame()])
            ->assertOk()
            ->assertJsonPath('data.classification', 'UNKNOWN')
            ->assertJsonPath('data.matched', null);
    }

    public function test_recognize_review_band_lists_candidate_but_no_match(): void
    {
        $person = $this->enrolledPerson($this->vector(1.0));
        $this->fakeAi($this->vector(0.7));

        $this->actingAs($this->tester)->post('/api/v1/faces/recognize', ['image' => $this->frame()])
            ->assertOk()
            ->assertJsonPath('data.classification', 'REVIEW')
            ->assertJsonPath('data.matched', null)
            ->assertJsonPath('data.candidates.0.person.uuid', $person->uuid);
    }

    public function test_recognize_reports_liveness_unavailable_instead_of_failing(): void
    {
        $this->enrolledPerson($this->vector(1.0));
        $this->fakeAi($this->vector(1.0), livenessStatus: 503);

        $this->actingAs($this->tester)->post('/api/v1/faces/recognize', ['image' => $this->frame()])
            ->assertOk()
            ->assertJsonPath('data.classification', 'MATCH')
            ->assertJsonPath('data.liveness.status', 'UNAVAILABLE');
    }

    public function test_recognize_requires_face_test_permission(): void
    {
        $this->postJson('/api/v1/faces/recognize', ['image' => $this->frame()])->assertStatus(401);
        // face.enroll alone may check quality but may not identify people.
        $this->actingAs($this->enroller)->post('/api/v1/faces/recognize', ['image' => $this->frame()], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_recognize_rejects_client_supplied_identity_or_vector(): void
    {
        $this->actingAs($this->tester)->post('/api/v1/faces/recognize', [
            'image' => $this->frame(), 'embedding' => '[1,2,3]',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_recognize_no_face_is_422(): void
    {
        Http::fake(['*/internal/v1/face/enroll' => Http::response(['detail' => [
            'success' => false, 'error_code' => 'NO_FACE', 'message' => 'No face', 'face_count' => 0,
        ]], 422)]);

        $this->actingAs($this->tester)->post('/api/v1/faces/recognize', ['image' => $this->frame()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_recognize_unregistered_model_is_incompatible(): void
    {
        $this->model->update(['status' => AIModelStatus::INACTIVE]);
        $this->fakeAi($this->vector(1.0));

        $this->actingAs($this->tester)->post('/api/v1/faces/recognize', ['image' => $this->frame()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INCOMPATIBLE_MODEL');
    }

    public function test_recognize_ai_unavailable_is_503(): void
    {
        Http::fake(['*/internal/v1/face/enroll' => Http::response([], 500)]);

        $this->actingAs($this->tester)->post('/api/v1/faces/recognize', ['image' => $this->frame()], ['Accept' => 'application/json'])
            ->assertStatus(503);
    }
}
