<?php

namespace Tests\Feature;

use App\Enums\AcademicStatus;
use App\Enums\AuditAction;
use App\Enums\ImportErrorCode;
use App\Enums\ImportJobStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\ImportError;
use App\Models\ImportJob;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Program;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\StudentStatusHistory;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * STD-012: Student import upload, mapping, validation, and preview API tests.
 */
class StudentImportApiTest extends TestCase
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

    // ================= 1. Authorization & Public Identity =================

    public function test_unauthenticated_import_endpoints_return_401(): void
    {
        $file = $this->createCsvFile('students.csv', ['student_code', 'first_name', 'last_name'], []);

        $this->postJson('/api/v1/students/import', ['file' => $file])
            ->assertStatus(401);

        $job = ImportJob::factory()->create();

        $this->getJson("/api/v1/students/imports/{$job->uuid}")
            ->assertStatus(401);

        $this->getJson("/api/v1/students/imports/{$job->uuid}/errors")
            ->assertStatus(401);

        $this->getJson("/api/v1/students/imports/{$job->uuid}/preview")
            ->assertStatus(401);
    }

    public function test_import_operations_without_permission_return_403(): void
    {
        $user = $this->unauthorizedUser();
        $file = $this->createCsvFile('students.csv', ['student_code', 'first_name', 'last_name'], []);

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file])
            ->assertStatus(403);

        $job = ImportJob::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/imports/{$job->uuid}")
            ->assertStatus(403);

        $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/imports/{$job->uuid}/errors")
            ->assertStatus(403);
    }

    public function test_numeric_id_and_unknown_uuid_return_404(): void
    {
        $user = $this->importUser();
        $job = ImportJob::factory()->create();

        // Numeric ID cannot be used in route binding
        $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/imports/{$job->id}")
            ->assertStatus(404);

        $unknownUuid = '00000000-0000-0000-0000-000000000000';
        $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/imports/{$unknownUuid}")
            ->assertStatus(404);
    }

    // ================= 2. File & Container Validation =================

    public function test_non_csv_and_invalid_mimes_rejected(): void
    {
        $user = $this->importUser();

        $exe = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');
        $response = $this->actingAs($user, 'web')
            ->post('/api/v1/students/import', ['file' => $exe], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('file', $response->json('error.fields') ?? []);
    }

    public function test_empty_file_transitions_to_failed(): void
    {
        $user = $this->importUser();
        $emptyFile = UploadedFile::fake()->createWithContent('empty.csv', '');

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $emptyFile]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', ImportJobStatus::FAILED->value);

        $job = ImportJob::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertEquals(ImportJobStatus::FAILED, $job->status);
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'error_code' => ImportErrorCode::EMPTY_FILE->value,
        ]);
    }

    public function test_missing_required_headers_transitions_to_failed(): void
    {
        $user = $this->importUser();
        $file = $this->createCsvFile('missing_header.csv', ['first_name', 'last_name'], [
            ['Somchai', 'Jaidee'],
        ]);

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', ImportJobStatus::FAILED->value);

        $job = ImportJob::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertEquals(ImportJobStatus::FAILED, $job->status);
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'field' => 'headers',
            'error_code' => ImportErrorCode::MISSING_REQUIRED_HEADER->value,
        ]);
    }

    // ================= 3. Row-level Validation & Academic Rules =================

    public function test_valid_csv_file_validated_successfully(): void
    {
        $user = $this->importUser();

        $dept = Department::factory()->create(['code' => 'IT', 'status' => AcademicStatus::ACTIVE]);
        $prog = Program::factory()->create(['department_id' => $dept->id, 'code' => 'SE', 'status' => AcademicStatus::ACTIVE]);
        $cls = Classroom::factory()->create(['department_id' => $dept->id, 'program_id' => $prog->id, 'code' => 'SE101', 'status' => AcademicStatus::ACTIVE]);

        $file = $this->createCsvFile('valid.csv', [
            'student_code', 'first_name', 'last_name', 'display_name', 'gender', 'department_code', 'program_code', 'classroom_code', 'academic_status',
        ], [
            ['STD-001', 'Somchai', 'Jaidee', 'Somchai J.', 'MALE', 'IT', 'SE', 'SE101', 'ACTIVE'],
            ['STD-002', 'Suda', 'Dee', 'Suda D.', 'FEMALE', 'IT', 'SE', 'SE101', 'LEAVE'],
        ]);

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', ImportJobStatus::VALIDATED->value)
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.success_rows', 0)
            ->assertJsonPath('data.failed_rows', 0);

        $job = ImportJob::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertSame(0, $job->errors()->count());
    }

    public function test_invalid_rows_create_structured_import_errors(): void
    {
        $user = $this->importUser();

        $dept = Department::factory()->create(['code' => 'ENG', 'status' => AcademicStatus::ACTIVE]);

        $file = $this->createCsvFile('errors.csv', [
            'student_code', 'first_name', 'last_name', 'gender', 'department_code', 'academic_status',
        ], [
            ['', 'Somchai', 'Jaidee', 'MALE', 'ENG', 'ACTIVE'], // Row 2: Missing student_code
            ['STD-101', 'Somchai', 'Jaidee', 'INVALID_GENDER', 'ENG', 'ACTIVE'], // Row 3: Invalid gender
            ['STD-102', 'Somchai', 'Jaidee', 'MALE', 'NON_EXISTENT_DEPT', 'ACTIVE'], // Row 4: Unknown department
            ['STD-103', 'Somchai', 'Jaidee', 'MALE', 'ENG', 'ALLOW'], // Row 5: Access decision prohibited
        ]);

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', ImportJobStatus::VALIDATED->value)
            ->assertJsonPath('data.total_rows', 4)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 4);

        $job = ImportJob::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertCount(4, $job->errors);

        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 2,
            'field' => 'student_code',
            'error_code' => ImportErrorCode::REQUIRED_FIELD_MISSING->value,
        ]);

        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 3,
            'field' => 'gender',
            'error_code' => ImportErrorCode::INVALID_ENUM->value,
        ]);

        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 4,
            'field' => 'department_code',
            'error_code' => ImportErrorCode::DEPARTMENT_NOT_FOUND->value,
        ]);

        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 5,
            'field' => 'academic_status',
            'error_code' => ImportErrorCode::INVALID_ENUM->value,
        ]);
    }

    public function test_duplicate_student_code_inside_file_detected(): void
    {
        $user = $this->importUser();

        $file = $this->createCsvFile('duplicates.csv', [
            'student_code', 'first_name', 'last_name',
        ], [
            ['STD-999', 'First', 'User'],
            ['STD-999', 'Second', 'User'], // Duplicate inside same file
        ]);

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.invalid_rows', 1);

        $job = ImportJob::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 3,
            'field' => 'student_code',
            'error_code' => ImportErrorCode::DUPLICATE_IN_FILE->value,
        ]);
    }

    public function test_duplicate_student_code_existing_in_database_detected(): void
    {
        $user = $this->importUser();
        $existing = StudentProfile::factory()->create(['student_code' => 'EXISTING-001']);

        $file = $this->createCsvFile('existing_conflict.csv', [
            'student_code', 'first_name', 'last_name',
        ], [
            ['EXISTING-001', 'New', 'Person'],
        ]);

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 1);

        $job = ImportJob::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 2,
            'field' => 'student_code',
            'error_code' => ImportErrorCode::DUPLICATE_STUDENT_CODE->value,
        ]);
    }

    public function test_academic_master_mismatch_detected(): void
    {
        $user = $this->importUser();

        $dept1 = Department::factory()->create(['code' => 'D1', 'status' => AcademicStatus::ACTIVE]);
        $dept2 = Department::factory()->create(['code' => 'D2', 'status' => AcademicStatus::ACTIVE]);
        $prog2 = Program::factory()->create(['department_id' => $dept2->id, 'code' => 'P2', 'status' => AcademicStatus::ACTIVE]);

        $file = $this->createCsvFile('mismatch.csv', [
            'student_code', 'first_name', 'last_name', 'department_code', 'program_code',
        ], [
            ['STD-500', 'Somchai', 'Test', 'D1', 'P2'], // Program belongs to D2, but row specifies D1
        ]);

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $response->assertStatus(201)
            ->assertJsonPath('data.invalid_rows', 1);

        $job = ImportJob::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $job->id,
            'row_number' => 2,
            'field' => 'program_code',
            'error_code' => ImportErrorCode::PROGRAM_DEPARTMENT_MISMATCH->value,
        ]);
    }

    // ================= 4. Zero Domain Side Effects =================

    public function test_validation_creates_zero_domain_entities(): void
    {
        $user = $this->importUser();

        $personCountBefore = Person::count();
        $studentCountBefore = StudentProfile::count();
        $historyCountBefore = StudentStatusHistory::count();
        $userCountBefore = User::count();

        $file = $this->createCsvFile('valid_students.csv', [
            'student_code', 'first_name', 'last_name', 'academic_status',
        ], [
            ['STD-A01', 'Alice', 'Smith', 'ACTIVE'],
            ['STD-A02', 'Bob', 'Jones', 'ACTIVE'],
        ]);

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file])
            ->assertStatus(201);

        // Crucial invariant: intake validation MUST NOT create students/persons/users
        $this->assertSame($personCountBefore, Person::count());
        $this->assertSame($studentCountBefore, StudentProfile::count());
        $this->assertSame($historyCountBefore, StudentStatusHistory::count());
        $this->assertSame($userCountBefore, User::count());
    }

    // ================= 5. Pagination, Preview & Resource Safety =================

    public function test_errors_endpoint_is_paginated_and_hides_internal_ids(): void
    {
        $user = $this->importUser();
        $job = ImportJob::factory()->create();

        ImportError::factory()->count(15)->create(['import_job_id' => $job->id]);

        $response = $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/imports/{$job->uuid}/errors?per_page=5&page=1");

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'row_number',
                        'field',
                        'value',
                        'error_code',
                        'error_message',
                        'created_at',
                    ],
                ],
                'links',
                'meta',
            ]);

        $firstError = $response->json('data.0');
        $this->assertArrayNotHasKey('id', $firstError);
        $this->assertArrayNotHasKey('import_job_id', $firstError);
    }

    public function test_preview_endpoint_returns_sample_rows_and_summary(): void
    {
        $user = $this->importUser();

        $file = $this->createCsvFile('preview.csv', [
            'student_code', 'first_name', 'last_name',
        ], [
            ['STD-P1', 'Preview', 'One'],
            ['STD-P2', 'Preview', 'Two'],
        ]);

        $uploadRes = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $jobUuid = $uploadRes->json('data.uuid');

        $response = $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/imports/{$jobUuid}/preview");

        $response->assertOk()
            ->assertJsonStructure([
                'job' => ['uuid', 'status', 'total_rows', 'valid_rows', 'invalid_rows'],
                'summary' => ['total_rows', 'valid_rows', 'invalid_rows', 'is_ready_for_execution'],
                'sample_rows',
                'sample_errors',
            ])
            ->assertJsonPath('summary.total_rows', 2)
            ->assertJsonPath('summary.is_ready_for_execution', true);
    }

    // ================= 6. Audit Trail =================

    public function test_import_upload_and_validation_create_audit_logs(): void
    {
        $user = $this->importUser();

        $file = $this->createCsvFile('audit_test.csv', [
            'student_code', 'first_name', 'last_name',
        ], [
            ['STD-AUD-1', 'Audit', 'Test'],
        ]);

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/students/import', ['file' => $file]);

        $jobUuid = $response->json('data.uuid');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_IMPORT_UPLOADED->value,
            'entity_type' => 'ImportJob',
            'entity_id' => $jobUuid,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_IMPORT_VALIDATED->value,
            'entity_type' => 'ImportJob',
            'entity_id' => $jobUuid,
            'user_id' => $user->id,
        ]);
    }
}
