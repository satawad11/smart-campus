<?php

namespace Tests\Feature;

use App\Enums\ImportJobStatus;
use App\Enums\PersonType;
use App\Enums\RoleStatus;
use App\Enums\StaffEmploymentStatus;
use App\Enums\StatusChangeSource;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\ImportJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Staff\StaffService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PPL-004 (Module 09): staff CSV import — intake validation with row errors,
 * preview, queued processing under each conflict mode, permission and audit.
 */
class StaffImportApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "staff_code,first_name,last_name,person_type,position,department_code,email,employment_status\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        Storage::fake('local');
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'IMP_'.uniqid(), 'name' => 'Importer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function csv(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('staff.csv', self::HEADER.$rows);
    }

    private function upload(User $user, string $rows, string $mode = 'CREATE_ONLY')
    {
        return $this->actingAs($user, 'web')->post('/api/v1/staff/import', [
            'file' => $this->csv($rows),
            'conflict_mode' => $mode,
        ], ['Accept' => 'application/json']);
    }

    public function test_requires_staff_import_permission(): void
    {
        $this->upload($this->userWith(['staff.view', 'student.import']), "T1,A,B,,,,,\n")->assertStatus(403);
    }

    public function test_valid_file_is_validated_then_processed_into_staff_with_import_history(): void
    {
        $user = $this->userWith(['staff.import']);
        Department::factory()->create(['code' => 'IT']);

        $res = $this->upload($user, "T1,สมชาย,ใจดี,TEACHER,ครู,it,somchai@example.test,\nS2,Malee,Dee,,ธุรการ,,,LEAVE\n");
        $res->assertStatus(201);
        $uuid = $res->json('data.uuid');

        $job = ImportJob::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ImportJobStatus::VALIDATED, $job->status);
        $this->assertSame(2, $job->valid_rows);

        $preview = $this->actingAs($user, 'web')->getJson("/api/v1/staff/imports/{$uuid}/preview")->assertOk();
        $preview->assertJsonPath('summary.is_ready_for_execution', true)->assertJsonCount(2, 'sample_rows');

        $this->actingAs($user, 'web')->postJson("/api/v1/staff/imports/{$uuid}/process")->assertStatus(202);

        $this->assertSame(ImportJobStatus::COMPLETED, $job->fresh()->status);
        $this->assertSame(2, $job->fresh()->success_rows);

        $teacher = StaffProfile::where('staff_code', 'T1')->firstOrFail();
        $this->assertSame(PersonType::TEACHER, $teacher->person->person_type);
        $this->assertSame('IT', $teacher->department->code);
        $this->assertSame(StatusChangeSource::IMPORT, $teacher->statusHistories()->first()->source);

        $onLeave = StaffProfile::where('staff_code', 'S2')->firstOrFail();
        $this->assertSame(PersonType::STAFF, $onLeave->person->person_type);
        $this->assertSame(StaffEmploymentStatus::LEAVE, $onLeave->employment_status);
        $this->assertSame(2, $onLeave->statusHistories()->count());

        foreach (['STAFF_IMPORT_UPLOADED', 'STAFF_IMPORT_VALIDATED', 'STAFF_IMPORT_PROCESSING_STARTED', 'STAFF_IMPORT_COMPLETED'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->where('entity_id', $uuid)->exists(), $action);
        }
        $this->assertSame(2, AuditLog::where('action', 'STAFF_CREATED')->count());
    }

    public function test_row_errors_are_reported_and_only_valid_rows_are_imported(): void
    {
        $user = $this->userWith(['staff.import']);

        $res = $this->upload($user, implode("\n", [
            'T1,A,B,,,,,',
            ',NoCode,X,,,,,',            // missing staff_code
            'T3,C,D,DOCTOR,,,,',         // bad person_type
            'T4,E,F,,,NOPE,,',           // unknown department
            'T5,G,H,,,,not-an-email,',   // bad email
            'T6,I,J,,,,,FIRED',          // bad status
            't1,K,L,,,,,',               // duplicate in file (case-insensitive)
        ])."\n")->assertStatus(201);
        $uuid = $res->json('data.uuid');

        $job = ImportJob::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(1, $job->valid_rows);
        $this->assertSame(6, $job->invalid_rows);

        $codes = collect($this->actingAs($user, 'web')->getJson("/api/v1/staff/imports/{$uuid}/errors")->assertOk()->json('data'))
            ->pluck('error_code')->all();
        foreach (['REQUIRED_FIELD_MISSING', 'INVALID_ENUM', 'DEPARTMENT_NOT_FOUND', 'INVALID_FORMAT', 'DUPLICATE_IN_FILE'] as $code) {
            $this->assertContains($code, $codes);
        }

        $this->actingAs($user, 'web')->postJson("/api/v1/staff/imports/{$uuid}/process")->assertStatus(202);
        $this->assertSame(['T1'], StaffProfile::pluck('staff_code')->all());
    }

    public function test_missing_header_or_empty_file_fails_intake(): void
    {
        $user = $this->userWith(['staff.import']);

        $res = $this->actingAs($user, 'web')->post('/api/v1/staff/import', [
            'file' => UploadedFile::fake()->createWithContent('staff.csv', "staff_code,first_name\nT1,A\n"),
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertSame(ImportJobStatus::FAILED, ImportJob::where('uuid', $res->json('data.uuid'))->first()->status);

        $res = $this->actingAs($user, 'web')->post('/api/v1/staff/import', [
            'file' => UploadedFile::fake()->createWithContent('staff.csv', self::HEADER),
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertSame(ImportJobStatus::FAILED, ImportJob::where('uuid', $res->json('data.uuid'))->first()->status);
    }

    public function test_conflict_modes_for_existing_staff(): void
    {
        $user = $this->userWith(['staff.import']);
        app(StaffService::class)->createStaff([
            'person' => ['first_name' => 'Anan', 'last_name' => 'K'],
            'staff_code' => 'T1',
            'position' => 'old',
        ], null, null);

        // CREATE_ONLY: an existing code is a validation error.
        $uuid = $this->upload($user, "T1,Anan,K,,new,,,\n")->json('data.uuid');
        $this->assertSame(0, ImportJob::where('uuid', $uuid)->first()->valid_rows);

        // A different person under the same code is always rejected.
        $uuid = $this->upload($user, "T1,Someone,Else,,new,,,\n", 'UPDATE_EXISTING')->json('data.uuid');
        $this->assertSame(0, ImportJob::where('uuid', $uuid)->first()->valid_rows);

        // SKIP_EXISTING leaves the record alone.
        $uuid = $this->upload($user, "T1,Anan,K,,new,,,\n", 'SKIP_EXISTING')->json('data.uuid');
        $this->actingAs($user, 'web')->postJson("/api/v1/staff/imports/{$uuid}/process")->assertStatus(202);
        $this->assertSame(1, ImportJob::where('uuid', $uuid)->first()->skipped_rows);
        $this->assertSame('old', StaffProfile::where('staff_code', 'T1')->first()->position);

        // UPDATE_EXISTING updates details and status.
        $uuid = $this->upload($user, "T1,Anan,K,,new,,,RETIRED\n", 'UPDATE_EXISTING')->json('data.uuid');
        $this->actingAs($user, 'web')->postJson("/api/v1/staff/imports/{$uuid}/process")->assertStatus(202);
        $staff = StaffProfile::where('staff_code', 'T1')->first();
        $this->assertSame('new', $staff->position);
        $this->assertSame(StaffEmploymentStatus::RETIRED, $staff->employment_status);
    }

    public function test_jobs_cannot_cross_between_student_and_staff_endpoints(): void
    {
        $user = $this->userWith(['staff.import', 'student.import']);
        $uuid = $this->upload($user, "T1,A,B,,,,,\n")->json('data.uuid');

        $this->actingAs($user, 'web')->getJson("/api/v1/students/imports/{$uuid}")->assertStatus(404);
        $this->actingAs($user, 'web')->getJson("/api/v1/imports/{$uuid}")->assertStatus(404);
        $this->actingAs($user, 'web')->postJson("/api/v1/imports/{$uuid}/process")->assertStatus(404);
        $this->actingAs($user, 'web')->getJson("/api/v1/staff/imports/{$uuid}")->assertOk();
    }

    public function test_a_job_cannot_be_processed_twice(): void
    {
        $user = $this->userWith(['staff.import']);
        $uuid = $this->upload($user, "T1,A,B,,,,,\n")->json('data.uuid');

        $this->actingAs($user, 'web')->postJson("/api/v1/staff/imports/{$uuid}/process")->assertStatus(202);
        $this->actingAs($user, 'web')->postJson("/api/v1/staff/imports/{$uuid}/process")
            ->assertStatus(422)->assertJsonPath('error.code', 'IMPORT_JOB_NOT_PROCESSABLE');
        $this->actingAs($user, 'web')->postJson("/api/v1/staff/imports/{$uuid}/process", ['conflict_mode' => 'UPDATE_EXISTING'])
            ->assertStatus(422);
    }
}
