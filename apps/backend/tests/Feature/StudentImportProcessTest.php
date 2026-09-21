<?php

namespace Tests\Feature;

use App\Enums\AcademicStatus;
use App\Enums\AuditAction;
use App\Enums\ImportJobStatus;
use App\Enums\PersonStatus;
use App\Enums\RoleStatus;
use App\Enums\StatusChangeSource;
use App\Enums\StudentAcademicStatus;
use App\Enums\UserStatus;
use App\Jobs\ProcessStudentImportJob;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\ImportJob;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Program;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Student\StudentImportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * STD-013: Queued student import execution tests.
 */
class StudentImportProcessTest extends TestCase
{
    use RefreshDatabase;

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
        $role = Role::create([
            'code' => 'IMP_'.uniqid(),
            'name' => 'Import Admin',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function importUser(): User
    {
        return $this->userWith(['student.import']);
    }

    private function unauthorizedUser(): User
    {
        return $this->userWith(['student.view']);
    }

    private function createCsvFile(string $filename, array $headers, array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    // ================= 1. Authorization & Endpoint Validation =================

    public function test_unauthenticated_process_returns_401(): void
    {
        $job = ImportJob::factory()->create(['status' => ImportJobStatus::VALIDATED]);

        $this->postJson("/api/v1/students/imports/{$job->uuid}/process")
            ->assertStatus(401);
    }

    public function test_process_without_permission_returns_403(): void
    {
        $user = $this->unauthorizedUser();
        $job = ImportJob::factory()->create(['status' => ImportJobStatus::VALIDATED]);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/students/imports/{$job->uuid}/process")
            ->assertStatus(403);
    }

    public function test_numeric_id_returns_404(): void
    {
        $user = $this->importUser();
        $job = ImportJob::factory()->create(['status' => ImportJobStatus::VALIDATED]);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/students/imports/{$job->id}/process")
            ->assertStatus(404);
    }

    public function test_non_validated_job_cannot_be_processed(): void
    {
        $user = $this->importUser();

        foreach ([ImportJobStatus::PENDING, ImportJobStatus::VALIDATING, ImportJobStatus::PROCESSING, ImportJobStatus::COMPLETED, ImportJobStatus::FAILED] as $invalidStatus) {
            $job = ImportJob::factory()->create(['status' => $invalidStatus]);

            $this->actingAs($user, 'web')
                ->postJson("/api/v1/students/imports/{$job->uuid}/process")
                ->assertStatus(422);
        }
    }

    // ================= 2. Queue Dispatch & State Transition =================

    public function test_process_dispatches_queue_job_and_sets_processing(): void
    {
        Queue::fake();
        $user = $this->importUser();

        $job = ImportJob::factory()->create([
            'status' => ImportJobStatus::VALIDATED,
            'total_rows' => 10,
            'valid_rows' => 10,
            'invalid_rows' => 0,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'web')
            ->postJson("/api/v1/students/imports/{$job->uuid}/process");

        $response->assertStatus(202)
            ->assertJsonPath('data.status', ImportJobStatus::PROCESSING->value);

        $this->assertEquals(ImportJobStatus::PROCESSING, $job->fresh()->status);
        $this->assertNotNull($job->fresh()->started_at);

        Queue::assertPushed(ProcessStudentImportJob::class, function ($pushedJob) use ($job) {
            return $pushedJob->importJob->id === $job->id;
        });

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_IMPORT_PROCESSING_STARTED->value,
            'entity_type' => 'ImportJob',
            'entity_id' => $job->uuid,
            'user_id' => $user->id,
        ]);
    }

    // ================= 3. Synchronous / Worker Execution =================

    public function test_worker_creates_students_via_student_service_with_exact_invariants(): void
    {
        $user = $this->importUser();

        $dept = Department::factory()->create(['code' => 'IT', 'status' => AcademicStatus::ACTIVE]);
        $prog = Program::factory()->create(['department_id' => $dept->id, 'code' => 'SE', 'status' => AcademicStatus::ACTIVE]);
        $cls = Classroom::factory()->create(['department_id' => $dept->id, 'program_id' => $prog->id, 'code' => 'SE101', 'status' => AcademicStatus::ACTIVE]);

        $file = $this->createCsvFile('students_exec.csv', [
            'student_code', 'first_name', 'last_name', 'display_name', 'gender', 'national_id', 'department_code', 'program_code', 'classroom_code', 'education_level', 'year_level', 'admission_year', 'academic_status',
        ], [
            ['STD-EXE-01', 'Somchai', 'Sukjai', 'Somchai S.', 'MALE', 'NAT-001', 'IT', 'SE', 'SE101', 'VOC_CERT', '1', '2026', 'ACTIVE'],
            ['STD-EXE-02', 'Suda', 'Meechai', 'Suda M.', 'FEMALE', 'NAT-002', 'IT', 'SE', 'SE101', 'VOC_CERT', '2', '2025', 'LEAVE'],
        ]);

        // 1. Upload & Validate
        $uploadRes = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $uploadRes->assertStatus(201);
        $jobUuid = $uploadRes->json('data.uuid');
        $job = ImportJob::where('uuid', $jobUuid)->firstOrFail();
        $this->assertEquals(ImportJobStatus::VALIDATED, $job->status);

        // 2. Execute directly via service (simulating queue worker)
        app(StudentImportService::class)->processImportJob($job);

        $freshJob = $job->fresh();
        $this->assertEquals(ImportJobStatus::COMPLETED, $freshJob->status);
        $this->assertSame(2, $freshJob->total_rows);
        $this->assertSame(2, $freshJob->valid_rows);
        $this->assertSame(0, $freshJob->invalid_rows);
        $this->assertSame(2, $freshJob->success_rows);
        $this->assertSame(0, $freshJob->failed_rows);
        $this->assertNotNull($freshJob->completed_at);

        // Verify Student 1 domain records
        $student1 = StudentProfile::where('student_code', 'STD-EXE-01')->firstOrFail();
        $this->assertEquals(StudentAcademicStatus::ACTIVE, $student1->academic_status);
        $this->assertEquals('VOC_CERT', $student1->education_level);
        $this->assertEquals(1, $student1->year_level);
        $this->assertEquals(2026, $student1->admission_year);
        $this->assertEquals($dept->id, $student1->department_id);
        $this->assertEquals($prog->id, $student1->program_id);
        $this->assertEquals($cls->id, $student1->classroom_id);

        $person1 = $student1->person;
        $this->assertEquals('Somchai', $person1->first_name);
        $this->assertEquals('Sukjai', $person1->last_name);
        $this->assertEquals('NAT-001', $person1->person_code);
        $this->assertEquals('MALE', $person1->gender);
        $this->assertEquals(PersonStatus::ACTIVE, $person1->status);

        // Verify Student 1 initial status history (source = IMPORT)
        $history1 = $student1->statusHistories()->firstOrFail();
        $this->assertNull($history1->old_status);
        $this->assertEquals(StudentAcademicStatus::ACTIVE, $history1->new_status);
        $this->assertEquals(StatusChangeSource::IMPORT, $history1->source);
        $this->assertEquals($user->id, $history1->changed_by_user_id);

        // Verify Student 2 domain records (LEAVE status)
        $student2 = StudentProfile::where('student_code', 'STD-EXE-02')->firstOrFail();
        $this->assertEquals(StudentAcademicStatus::LEAVE, $student2->academic_status);
        $history2 = $student2->statusHistories()->firstOrFail();
        $this->assertEquals(StudentAcademicStatus::LEAVE, $history2->new_status);
        $this->assertEquals(StatusChangeSource::IMPORT, $history2->source);

        // Verify Audit Logs
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_CREATED->value,
            'entity_type' => 'STUDENT',
            'entity_id' => $student1->uuid,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_IMPORT_COMPLETED->value,
            'entity_type' => 'ImportJob',
            'entity_id' => $job->uuid,
        ]);
    }

    public function test_invalid_rows_are_skipped_during_execution(): void
    {
        $user = $this->importUser();

        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        // 3 rows: row 2 valid, row 3 invalid (missing student_code), row 4 valid
        $file = $this->createCsvFile('mixed.csv', [
            'student_code', 'first_name', 'last_name', 'department_code',
        ], [
            ['STD-MIX-01', 'Valid', 'One', 'ENG'],
            ['', 'Invalid', 'Two', 'ENG'], // Row 3: fails validation
            ['STD-MIX-03', 'Valid', 'Three', 'ENG'],
        ]);

        $uploadRes = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $job = ImportJob::where('uuid', $uploadRes->json('data.uuid'))->firstOrFail();
        $this->assertSame(3, $job->total_rows);
        $this->assertSame(2, $job->valid_rows);
        $this->assertSame(1, $job->invalid_rows);

        // Execute
        app(StudentImportService::class)->processImportJob($job);

        $freshJob = $job->fresh();
        $this->assertEquals(ImportJobStatus::COMPLETED, $freshJob->status);
        $this->assertSame(3, $freshJob->total_rows);
        $this->assertSame(2, $freshJob->valid_rows);
        $this->assertSame(1, $freshJob->invalid_rows);
        $this->assertSame(2, $freshJob->success_rows);
        $this->assertSame(0, $freshJob->failed_rows);

        // Verify valid students created
        $this->assertTrue(StudentProfile::where('student_code', 'STD-MIX-01')->exists());
        $this->assertTrue(StudentProfile::where('student_code', 'STD-MIX-03')->exists());
        $this->assertFalse(Person::where('first_name', 'Invalid')->exists());
    }

    public function test_runtime_domain_conflict_recorded_as_failed_row_without_aborting_other_rows(): void
    {
        $user = $this->importUser();

        $dept = Department::factory()->create(['code' => 'MED', 'status' => AcademicStatus::ACTIVE]);

        // 2 valid rows in CSV
        $file = $this->createCsvFile('runtime_race.csv', [
            'student_code', 'first_name', 'last_name', 'department_code',
        ], [
            ['STD-RACE-01', 'Race', 'One', 'MED'],
            ['STD-RACE-02', 'Race', 'Two', 'MED'],
        ]);

        $uploadRes = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $job = ImportJob::where('uuid', $uploadRes->json('data.uuid'))->firstOrFail();

        // Simulate concurrent student creation between validation and processing
        StudentProfile::factory()->create(['student_code' => 'STD-RACE-01']);

        // Execute
        app(StudentImportService::class)->processImportJob($job);

        $freshJob = $job->fresh();
        $this->assertEquals(ImportJobStatus::COMPLETED, $freshJob->status);
        $this->assertSame(2, $freshJob->total_rows);
        $this->assertSame(2, $freshJob->valid_rows);
        $this->assertSame(0, $freshJob->invalid_rows);
        $this->assertSame(1, $freshJob->success_rows);
        $this->assertSame(1, $freshJob->failed_rows);

        // Invariant check: valid_rows === success_rows + failed_rows
        $this->assertSame($freshJob->valid_rows, $freshJob->success_rows + $freshJob->failed_rows);

        // Second row succeeded despite first row failing
        $this->assertTrue(StudentProfile::where('student_code', 'STD-RACE-02')->exists());

        // Error record was saved for the runtime conflict
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 2,
            'field' => 'student_code',
        ]);
    }

    public function test_missing_file_during_execution_fails_safely(): void
    {
        $user = $this->importUser();

        $job = ImportJob::factory()->create([
            'status' => ImportJobStatus::VALIDATED,
            'file_path' => 'imports/non_existent.csv',
            'created_by' => $user->id,
        ]);

        app(StudentImportService::class)->processImportJob($job);

        $freshJob = $job->fresh();
        $this->assertEquals(ImportJobStatus::FAILED, $freshJob->status);
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'error_code' => 'FILE_NOT_FOUND',
        ]);
    }

    // ================= 4. Boundary & Invariant Checks =================

    public function test_import_execution_creates_zero_users_or_face_profiles(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ART', 'status' => AcademicStatus::ACTIVE]);

        $userCountBefore = User::count();

        $file = $this->createCsvFile('no_users.csv', [
            'student_code', 'first_name', 'last_name', 'department_code',
        ], [
            ['STD-NOUSER', 'Art', 'Student', 'ART'],
        ]);

        $uploadRes = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $job = ImportJob::where('uuid', $uploadRes->json('data.uuid'))->firstOrFail();
        app(StudentImportService::class)->processImportJob($job);

        // Verify NO User accounts created
        $this->assertSame($userCountBefore, User::count());
    }
}
