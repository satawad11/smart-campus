<?php

namespace Tests\Feature;

use App\Enums\AcademicStatus;
use App\Enums\AuditAction;
use App\Enums\ImportConflictMode;
use App\Enums\ImportErrorCode;
use App\Enums\ImportJobStatus;
use App\Enums\PersonStatus;
use App\Enums\PersonType;
use App\Enums\RoleStatus;
use App\Enums\StatusChangeSource;
use App\Enums\StudentAcademicStatus;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\ImportJob;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\StudentStatusHistory;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Student\StudentImportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * STD-014: Student import identity conflict handling tests.
 */
class StudentImportConflictTest extends TestCase
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

    // ================= 1. Identity Mismatch Detection =================

    public function test_identity_mismatch_detected_when_student_code_exists_with_different_name(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        // Create existing student in database
        $person = Person::factory()->create([
            'first_name' => 'OriginalFirst',
            'last_name' => 'OriginalLast',
            'person_code' => 'NAT-12345',
            'person_type' => PersonType::STUDENT,
            'status' => PersonStatus::ACTIVE,
        ]);
        StudentProfile::factory()->create([
            'person_id' => $person->id,
            'student_code' => 'STD-CONFLICT-01',
            'department_id' => $dept->id,
            'academic_status' => StudentAcademicStatus::ACTIVE,
        ]);

        // Upload CSV with same student_code but completely DIFFERENT name
        $file = $this->createCsvFile('mismatch_name.csv', [
            'student_code', 'first_name', 'last_name', 'national_id', 'department_code',
        ], [
            ['STD-CONFLICT-01', 'CompletelyDifferentFirst', 'DifferentLast', 'NAT-12345', 'ENG'],
        ]);

        $res = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $res->assertStatus(201)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 1);

        $job = ImportJob::where('uuid', $res->json('data.uuid'))->firstOrFail();

        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 2,
            'error_code' => ImportErrorCode::IDENTITY_MISMATCH->value,
        ]);

        // Verify preview flags the identity mismatch
        $previewRes = $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/imports/{$job->uuid}/preview");

        $previewRes->assertStatus(200)
            ->assertJsonPath('summary.identity_mismatches', 1)
            ->assertJsonPath('summary.conflict_rows', 1)
            ->assertJsonPath('sample_rows.0._has_conflict', true)
            ->assertJsonPath('sample_rows.0._conflict_type', ImportErrorCode::IDENTITY_MISMATCH->value);
    }

    public function test_identity_mismatch_detected_when_student_code_exists_with_different_national_id(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        // Create existing student
        $person = Person::factory()->create([
            'first_name' => 'Somchai',
            'last_name' => 'Sukjai',
            'person_code' => 'NAT-REAL-999',
            'person_type' => PersonType::STUDENT,
        ]);
        StudentProfile::factory()->create([
            'person_id' => $person->id,
            'student_code' => 'STD-CONFLICT-02',
            'department_id' => $dept->id,
        ]);

        // Upload CSV with same student_code & name, but DIFFERENT national_id
        $file = $this->createCsvFile('mismatch_nat.csv', [
            'student_code', 'first_name', 'last_name', 'national_id', 'department_code',
        ], [
            ['STD-CONFLICT-02', 'Somchai', 'Sukjai', 'NAT-DIFFERENT-000', 'ENG'],
        ]);

        $res = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $res->assertStatus(201);
        $job = ImportJob::where('uuid', $res->json('data.uuid'))->firstOrFail();

        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 2,
            'error_code' => ImportErrorCode::IDENTITY_MISMATCH->value,
        ]);
    }

    // ================= 2. Person Code / National ID Conflict =================

    public function test_person_code_conflict_detected_when_national_id_belongs_to_another_student(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        // Student A has national ID NAT-PERSON-A
        $personA = Person::factory()->create([
            'first_name' => 'StudentA',
            'last_name' => 'LastNameA',
            'person_code' => 'NAT-PERSON-A',
        ]);
        StudentProfile::factory()->create([
            'person_id' => $personA->id,
            'student_code' => 'STD-EXISTING-A',
            'department_id' => $dept->id,
        ]);

        // Upload CSV with a NEW student_code, but using Person A's national_id
        $file = $this->createCsvFile('person_conflict.csv', [
            'student_code', 'first_name', 'last_name', 'national_id', 'department_code',
        ], [
            ['STD-NEW-B', 'StudentB', 'LastNameB', 'NAT-PERSON-A', 'ENG'],
        ]);

        $res = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $res->assertStatus(201);
        $job = ImportJob::where('uuid', $res->json('data.uuid'))->firstOrFail();

        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 2,
            'error_code' => ImportErrorCode::PERSON_CODE_CONFLICT->value,
        ]);
    }

    public function test_intra_file_person_code_conflict_detected(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        // CSV has two different student codes with the SAME national_id
        $file = $this->createCsvFile('intra_nat_conflict.csv', [
            'student_code', 'first_name', 'last_name', 'national_id', 'department_code',
        ], [
            ['STD-FILE-01', 'Alice', 'Smith', 'NAT-SAME-DUP', 'ENG'],
            ['STD-FILE-02', 'Bob', 'Jones', 'NAT-SAME-DUP', 'ENG'],
        ]);

        $res = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $res->assertStatus(201);
        $job = ImportJob::where('uuid', $res->json('data.uuid'))->firstOrFail();

        // Row 3 (the second student with duplicate national_id) should have PERSON_CODE_CONFLICT error
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 3,
            'error_code' => ImportErrorCode::PERSON_CODE_CONFLICT->value,
        ]);
    }

    // ================= 3. Conflict Modes: CREATE_ONLY vs SKIP_EXISTING vs UPDATE_EXISTING =================

    public function test_create_only_mode_rejects_existing_student_code_even_with_matching_identity(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        $person = Person::factory()->create([
            'first_name' => 'Charlie',
            'last_name' => 'Brown',
            'person_code' => 'NAT-CHARLIE',
        ]);
        StudentProfile::factory()->create([
            'person_id' => $person->id,
            'student_code' => 'STD-CHARLIE',
            'department_id' => $dept->id,
        ]);

        $file = $this->createCsvFile('create_only.csv', [
            'student_code', 'first_name', 'last_name', 'national_id', 'department_code',
        ], [
            ['STD-CHARLIE', 'Charlie', 'Brown', 'NAT-CHARLIE', 'ENG'],
        ]);

        $res = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', [
                'file' => $file,
                'conflict_mode' => ImportConflictMode::CREATE_ONLY->value,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.conflict_mode', 'CREATE_ONLY')
            ->assertJsonPath('data.invalid_rows', 1);

        $job = ImportJob::where('uuid', $res->json('data.uuid'))->firstOrFail();
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 2,
            'error_code' => ImportErrorCode::DUPLICATE_STUDENT_CODE->value,
        ]);
    }

    public function test_skip_existing_mode_allows_validation_and_increments_skipped_rows_counter(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        $person = Person::factory()->create([
            'first_name' => 'David',
            'last_name' => 'Miller',
            'person_code' => 'NAT-DAVID',
        ]);
        $existingProfile = StudentProfile::factory()->create([
            'person_id' => $person->id,
            'student_code' => 'STD-DAVID',
            'department_id' => $dept->id,
            'year_level' => 1,
            'academic_status' => StudentAcademicStatus::ACTIVE,
        ]);

        $personCountBefore = Person::count();
        $historyCountBefore = StudentStatusHistory::count();

        // CSV row with matching identity and new year_level 2, plus one brand new student
        $file = $this->createCsvFile('skip_mode.csv', [
            'student_code', 'first_name', 'last_name', 'national_id', 'department_code', 'year_level',
        ], [
            ['STD-DAVID', 'David', 'Miller', 'NAT-DAVID', 'ENG', '2'],
            ['STD-NEW-01', 'Eva', 'Green', 'NAT-EVA', 'ENG', '1'],
        ]);

        // Upload with SKIP_EXISTING
        $res = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', [
                'file' => $file,
                'conflict_mode' => ImportConflictMode::SKIP_EXISTING->value,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.conflict_mode', 'SKIP_EXISTING')
            ->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.skipped_rows', 0);

        $job = ImportJob::where('uuid', $res->json('data.uuid'))->firstOrFail();

        // Execute import
        app(StudentImportService::class)->processImportJob($job);

        $freshJob = $job->fresh();
        $this->assertEquals(ImportJobStatus::COMPLETED, $freshJob->status);
        $this->assertSame(2, $freshJob->total_rows);
        $this->assertSame(2, $freshJob->valid_rows);
        $this->assertSame(0, $freshJob->invalid_rows);
        $this->assertSame(1, $freshJob->success_rows); // only new student is success
        $this->assertSame(0, $freshJob->failed_rows);
        $this->assertSame(1, $freshJob->skipped_rows); // existing student was skipped

        // Invariant check: valid_rows === success_rows + failed_rows + skipped_rows
        $this->assertSame($freshJob->valid_rows, $freshJob->success_rows + $freshJob->failed_rows + $freshJob->skipped_rows);

        // Existing student was NOT mutated (no Person, Profile, or History change, no STUDENT_UPDATED audit)
        $this->assertSame(1, $existingProfile->fresh()->year_level);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => AuditAction::STUDENT_UPDATED->value,
            'entity_id' => $existingProfile->uuid,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => AuditAction::STUDENT_STATUS_CHANGED->value,
            'entity_id' => $existingProfile->uuid,
        ]);

        // New student was created
        $this->assertTrue(StudentProfile::where('student_code', 'STD-NEW-01')->exists());

        // Import completion audit includes skipped_rows
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_IMPORT_COMPLETED->value,
            'entity_id' => $job->uuid,
            'metadata->skipped_rows' => 1,
            'metadata->success_rows' => 1,
        ]);
    }

    public function test_update_existing_mode_updates_matching_identity_safely(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        $person = Person::factory()->create([
            'first_name' => 'Frank',
            'last_name' => 'Ocean',
            'person_code' => 'NAT-FRANK',
        ]);
        $existingProfile = StudentProfile::factory()->create([
            'person_id' => $person->id,
            'student_code' => 'STD-FRANK',
            'department_id' => $dept->id,
            'year_level' => 1,
            'academic_status' => StudentAcademicStatus::ACTIVE,
        ]);

        // CSV row with matching identity, updated display_name, year_level = 3, academic_status = LEAVE
        $file = $this->createCsvFile('update_mode.csv', [
            'student_code', 'first_name', 'last_name', 'display_name', 'national_id', 'department_code', 'year_level', 'academic_status',
        ], [
            ['STD-FRANK', 'Frank', 'Ocean', 'Frankie O.', 'NAT-FRANK', 'ENG', '3', 'LEAVE'],
        ]);

        // Upload with UPDATE_EXISTING
        $res = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', [
                'file' => $file,
                'conflict_mode' => ImportConflictMode::UPDATE_EXISTING->value,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.conflict_mode', 'UPDATE_EXISTING')
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.invalid_rows', 0);

        $job = ImportJob::where('uuid', $res->json('data.uuid'))->firstOrFail();

        // Execute import
        app(StudentImportService::class)->processImportJob($job);

        $freshJob = $job->fresh();
        $this->assertEquals(ImportJobStatus::COMPLETED, $freshJob->status);
        $this->assertSame(1, $freshJob->success_rows);
        $this->assertSame(0, $freshJob->failed_rows);
        $this->assertSame(0, $freshJob->skipped_rows);

        // Invariant check: valid_rows === success_rows + failed_rows + skipped_rows
        $this->assertSame($freshJob->valid_rows, $freshJob->success_rows + $freshJob->failed_rows + $freshJob->skipped_rows);

        // Verify updated student profile & person
        $freshProfile = $existingProfile->fresh();
        $this->assertSame(3, $freshProfile->year_level);
        $this->assertEquals(StudentAcademicStatus::LEAVE, $freshProfile->academic_status);
        $this->assertEquals('Frankie O.', $freshProfile->person->display_name);

        // Verify status history has new record with IMPORT source
        $latestHistory = $freshProfile->statusHistories()->latest('id')->firstOrFail();
        $this->assertEquals(StudentAcademicStatus::LEAVE, $latestHistory->new_status);
        $this->assertEquals(StatusChangeSource::IMPORT, $latestHistory->source);

        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_UPDATED->value,
            'entity_type' => 'STUDENT',
            'entity_id' => $existingProfile->uuid,
        ]);
    }

    public function test_update_existing_mode_strictly_rejects_identity_mismatch_at_runtime(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        // Valid CSV initially
        $file = $this->createCsvFile('concurrent_mismatch.csv', [
            'student_code', 'first_name', 'last_name', 'national_id', 'department_code',
        ], [
            ['STD-RACE-CON', 'Harry', 'Potter', 'NAT-HARRY', 'ENG'],
        ]);

        $res = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', [
                'file' => $file,
                'conflict_mode' => ImportConflictMode::UPDATE_EXISTING->value,
            ]);

        $job = ImportJob::where('uuid', $res->json('data.uuid'))->firstOrFail();

        // Simulate concurrent creation of STD-RACE-CON with a DIFFERENT person/name
        $person = Person::factory()->create([
            'first_name' => 'Voldemort',
            'last_name' => 'Riddle',
            'person_code' => 'NAT-VOLDE',
        ]);
        StudentProfile::factory()->create([
            'person_id' => $person->id,
            'student_code' => 'STD-RACE-CON',
            'department_id' => $dept->id,
        ]);

        // Execute import
        app(StudentImportService::class)->processImportJob($job);

        $freshJob = $job->fresh();
        $this->assertEquals(ImportJobStatus::COMPLETED, $freshJob->status);
        $this->assertSame(0, $freshJob->success_rows);
        $this->assertSame(1, $freshJob->failed_rows);
        $this->assertSame(0, $freshJob->skipped_rows);

        // Identity mismatch error recorded
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 2,
            'error_code' => ImportErrorCode::IDENTITY_MISMATCH->value,
        ]);

        // Person was NOT mutated
        $this->assertEquals('Voldemort', $person->fresh()->first_name);
    }

    // ================= 4. Policy Stability & Process Override Rejection =================

    public function test_process_request_rejects_conflict_mode_override(): void
    {
        $user = $this->importUser();
        $job = ImportJob::factory()->create([
            'status' => ImportJobStatus::VALIDATED,
            'conflict_mode' => ImportConflictMode::CREATE_ONLY,
            'total_rows' => 1,
            'valid_rows' => 1,
            'created_by' => $user->id,
        ]);

        // Attempting to override conflict_mode at process time MUST return 422 Unprocessable Entity
        $response = $this->actingAs($user, 'web')
            ->postJson("/api/v1/students/imports/{$job->uuid}/process", [
                'conflict_mode' => 'UPDATE_EXISTING',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.fields.conflict_mode.0', 'conflict_mode cannot be overridden at process time. Revalidation is required to change conflict policy.');

        // Persisted job mode was not altered
        $this->assertEquals(ImportConflictMode::CREATE_ONLY, $job->fresh()->conflict_mode);
    }

    public function test_persisted_conflict_mode_is_stable_across_upload_preview_and_process(): void
    {
        $user = $this->importUser();
        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        $file = $this->createCsvFile('stable_mode.csv', [
            'student_code', 'first_name', 'last_name', 'national_id', 'department_code',
        ], [
            ['STD-STABLE-01', 'George', 'Clark', 'NAT-GEORGE', 'ENG'],
        ]);

        // 1. Upload with explicit SKIP_EXISTING
        $uploadRes = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', [
                'file' => $file,
                'conflict_mode' => ImportConflictMode::SKIP_EXISTING->value,
            ]);

        $uploadRes->assertStatus(201);
        $jobUuid = $uploadRes->json('data.uuid');
        $job = ImportJob::where('uuid', $jobUuid)->firstOrFail();
        $this->assertEquals(ImportConflictMode::SKIP_EXISTING, $job->conflict_mode);

        // 2. Preview reports exact persisted mode
        $previewRes = $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/imports/{$jobUuid}/preview");

        $previewRes->assertStatus(200)
            ->assertJsonPath('summary.conflict_mode', 'SKIP_EXISTING');

        // 3. Process executes under the exact persisted mode
        $processRes = $this->actingAs($user, 'web')
            ->postJson("/api/v1/students/imports/{$jobUuid}/process");

        $processRes->assertStatus(202)
            ->assertJsonPath('data.conflict_mode', 'SKIP_EXISTING');

        $this->assertEquals(ImportConflictMode::SKIP_EXISTING, $job->fresh()->conflict_mode);
    }
}
