<?php

namespace Tests\Feature;

use App\Enums\AcademicStatus;
use App\Enums\AuditAction;
use App\Enums\PersonStatus;
use App\Enums\PersonType;
use App\Enums\RoleStatus;
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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STD-018: Final Acceptance & Security Gate for Module 02 (Person + Student + Academic Structure).
 *
 * Comprehensive acceptance, security, regression, and architecture test suite.
 */
class Module02AcceptanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('public');
        Storage::fake('local');
    }

    private function createPermittedUser(array $permissions, string $username = 'mod2_admin'): User
    {
        $user = User::factory()->create([
            'username' => $username.'_'.Str::random(4),
            'email' => $username.'_'.Str::random(4).'@example.com',
            'status' => UserStatus::ACTIVE,
            'must_change_password' => false,
            'mfa_enabled' => false,
        ]);

        $role = Role::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'ROLE_'.strtoupper(Str::random(6)),
            'name' => 'Test Role',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);

        if (! empty($permissions)) {
            $permModels = Permission::whereIn('code', $permissions)->get();
            $role->permissions()->attach($permModels->pluck('id')->toArray());
        }

        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    // =========================================================================
    // SECTION A: ACADEMIC MASTER DATA & HIERARCHY (STD-001, STD-005)
    // =========================================================================

    public function test_academic_master_data_crud_hierarchy_and_permissions(): void
    {
        $viewer = $this->createPermittedUser(['academic.view'], 'academic_viewer');
        $manager = $this->createPermittedUser(['academic.view', 'academic.manage'], 'academic_mgr');
        $unauth = $this->createPermittedUser(['student.view'], 'no_academic_user');

        // 1. Authorization: academic.view required to list, academic.manage to create/update
        $this->actingAs($unauth, 'web')->getJson('/api/v1/departments')->assertStatus(403);
        $this->actingAs($viewer, 'web')->getJson('/api/v1/departments')->assertStatus(200);
        $this->actingAs($viewer, 'web')->postJson('/api/v1/departments', [
            'code' => 'D-ENG',
            'name' => 'Engineering',
        ])->assertStatus(403);

        // 2. Create Department as Manager
        $deptResp = $this->actingAs($manager, 'web')->postJson('/api/v1/departments', [
            'code' => 'D-ENG',
            'name' => 'Engineering',
            'description' => 'Faculty of Engineering',
        ])->assertStatus(201);
        $deptUuid = $deptResp->json('data.uuid');
        $this->assertNotEmpty($deptUuid);
        $this->assertDatabaseHas('departments', ['code' => 'D-ENG']);

        // 3. Create Program bound to Department
        $progResp = $this->actingAs($manager, 'web')->postJson('/api/v1/programs', [
            'department_uuid' => $deptUuid,
            'code' => 'P-CS',
            'name' => 'Computer Science',
            'education_level' => 'VOC_CERT',
        ])->assertStatus(201);
        $progUuid = $progResp->json('data.uuid');
        $this->assertDatabaseHas('programs', ['code' => 'P-CS']);

        // 4. Create Classroom bound to Program & Department
        $classResp = $this->actingAs($manager, 'web')->postJson('/api/v1/classrooms', [
            'department_uuid' => $deptUuid,
            'program_uuid' => $progUuid,
            'code' => 'C-CS101',
            'name' => 'CS Year 1 Room A',
            'academic_year' => '2567',
            'year_level' => 1,
        ])->assertStatus(201);
        $classUuid = $classResp->json('data.uuid');
        $this->assertDatabaseHas('classrooms', ['code' => 'C-CS101']);

        // 5. Invariant: Master Data uses UUIDs and never reveals internal integer IDs
        $deptData = $deptResp->json('data');
        $this->assertArrayNotHasKey('id', $deptData);
        $this->assertArrayHasKey('uuid', $deptData);

        // 6. Invariant: Master data lifecycle is ACTIVE/INACTIVE; no DELETE routes exist
        $this->assertFalse(RouteFacade::has('api.v1.departments.destroy'));
        $this->actingAs($manager, 'web')->deleteJson("/api/v1/departments/{$deptUuid}")->assertStatus(405);
    }

    // =========================================================================
    // SECTION B: PERSON & STUDENT PROFILE IDENTITY SEPARATION (STD-002..007)
    // =========================================================================

    public function test_student_creation_identity_separation_and_invariants(): void
    {
        $mgr = $this->createPermittedUser(['student.view', 'student.create', 'student.update', 'academic.view']);

        $dept = Department::create(['uuid' => (string) Str::uuid(), 'code' => 'D-IT', 'name' => 'IT Dept', 'status' => AcademicStatus::ACTIVE]);
        $prog = Program::create(['uuid' => (string) Str::uuid(), 'department_id' => $dept->id, 'code' => 'P-SE', 'name' => 'SE', 'education_level' => 'VOC_CERT', 'status' => AcademicStatus::ACTIVE]);
        $class = Classroom::create(['uuid' => (string) Str::uuid(), 'department_id' => $dept->id, 'program_id' => $prog->id, 'code' => 'C-SE1', 'name' => 'SE 1', 'academic_year' => '2567', 'year_level' => 1, 'status' => AcademicStatus::ACTIVE]);

        // 1. Create Student via API
        $createResp = $this->actingAs($mgr, 'web')->postJson('/api/v1/students', [
            'person' => [
                'person_code' => 'P-2026-001',
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'display_name' => 'Alice S.',
                'gender' => 'FEMALE',
            ],
            'student_code' => 'STD-2026-001',
            'department_uuid' => $dept->uuid,
            'program_uuid' => $prog->uuid,
            'classroom_uuid' => $class->uuid,
            'education_level' => 'VOC_CERT',
            'year_level' => 1,
        ]);

        $createResp->assertStatus(201);
        $studentUuid = $createResp->json('data.uuid');

        // 2. Identity separation: Person is created with ACTIVE status; StudentProfile is created with ACTIVE academic_status
        $profile = StudentProfile::where('uuid', $studentUuid)->firstOrFail();
        $this->assertNotNull($profile->person_id);
        $person = Person::findOrFail($profile->person_id);

        $this->assertEquals('Alice', $person->first_name);
        $this->assertEquals(PersonStatus::ACTIVE, $person->status);
        $this->assertEquals(StudentAcademicStatus::ACTIVE, $profile->academic_status);

        // 3. Invariant: Creating a student does NOT automatically create a User record
        $this->assertNull(User::where('person_id', $person->id)->first());

        // 4. Invariant: Public payload uses UUIDs, no internal integer IDs exposed
        $data = $createResp->json('data');
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('person_id', $data);
        $this->assertArrayNotHasKey('department_id', $data);

        // 5. Initial status history record created atomically
        $this->assertDatabaseHas('student_status_histories', [
            'student_profile_id' => $profile->id,
            'new_status' => 'ACTIVE',
            'old_status' => null,
        ]);

        // 6. Audit log created for student creation
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_CREATED->value,
            'entity_type' => 'STUDENT',
            'entity_id' => $studentUuid,
        ]);
    }

    // =========================================================================
    // SECTION C: ACADEMIC STATUS WORKFLOW & APPEND-ONLY HISTORY (STD-008)
    // =========================================================================

    public function test_academic_status_transition_workflow_and_audit(): void
    {
        $statusChanger = $this->createPermittedUser(['student.view', 'student.status.change'], 'status_officer');
        $viewerOnly = $this->createPermittedUser(['student.view'], 'viewer_only');

        $dept = Department::create(['uuid' => (string) Str::uuid(), 'code' => 'D-MATH', 'name' => 'Math Dept', 'status' => AcademicStatus::ACTIVE]);
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'person_code' => 'P-BOB-01',
            'person_type' => PersonType::STUDENT,
            'first_name' => 'Bob',
            'last_name' => 'Jones',
            'status' => PersonStatus::ACTIVE,
        ]);
        $profile = StudentProfile::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'student_code' => 'STD-2026-002',
            'department_id' => $dept->id,
            'education_level' => 'VOC_CERT',
            'year_level' => 1,
            'academic_status' => StudentAcademicStatus::ACTIVE,
        ]);

        // 1. Authorization: student.status.change required
        $this->actingAs($viewerOnly, 'web')->patchJson("/api/v1/students/{$profile->uuid}/status", [
            'status' => 'SUSPENDED',
            'reason' => 'Disciplinary action',
        ])->assertStatus(403);

        // 2. Reject no-op status change
        $this->actingAs($statusChanger, 'web')->patchJson("/api/v1/students/{$profile->uuid}/status", [
            'status' => 'ACTIVE',
            'reason' => 'Same status',
        ])->assertStatus(422);

        // 3. Execute valid transition: ACTIVE -> SUSPENDED
        $transitionResp = $this->actingAs($statusChanger, 'web')->patchJson("/api/v1/students/{$profile->uuid}/status", [
            'status' => 'SUSPENDED',
            'reason' => 'Disciplinary temporary suspension',
        ]);
        $transitionResp->assertStatus(200);

        // Invariant: StudentProfile academic_status changed
        $profile->refresh();
        $this->assertEquals(StudentAcademicStatus::SUSPENDED, $profile->academic_status);

        // Invariant: Person.status remains ACTIVE (identity is not suspended, only academic standing)
        $person->refresh();
        $this->assertEquals(PersonStatus::ACTIVE, $person->status);

        // Invariant: Append-only history record created with actor context
        $this->assertDatabaseHas('student_status_histories', [
            'student_profile_id' => $profile->id,
            'old_status' => 'ACTIVE',
            'new_status' => 'SUSPENDED',
            'reason' => 'Disciplinary temporary suspension',
            'changed_by_user_id' => $statusChanger->id,
        ]);

        // Invariant: Audit log recorded
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_STATUS_CHANGED->value,
            'entity_type' => 'STUDENT',
            'entity_id' => $profile->uuid,
        ]);

        // 4. Query status history via canonical route
        $historyResp = $this->actingAs($viewerOnly, 'web')->getJson("/api/v1/students/{$profile->uuid}/status-history");
        $historyResp->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'uuid',
                        'old_status',
                        'new_status',
                        'reason',
                        'created_at',
                        'changed_by' => ['uuid', 'username'],
                    ],
                ],
                'meta' => ['pagination'],
            ]);
    }

    // =========================================================================
    // SECTION D: STUDENT ARCHIVE LIFECYCLE (STD-009)
    // =========================================================================

    public function test_student_archive_flow_and_data_preservation(): void
    {
        $archiver = $this->createPermittedUser(['student.view', 'student.archive'], 'archivist');
        $unauth = $this->createPermittedUser(['student.view'], 'unauth_archivist');

        $dept = Department::create(['uuid' => (string) Str::uuid(), 'code' => 'D-SCI', 'name' => 'Science', 'status' => AcademicStatus::ACTIVE]);
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'person_code' => 'P-CHARLIE-01',
            'person_type' => PersonType::STUDENT,
            'first_name' => 'Charlie',
            'last_name' => 'Brown',
            'status' => PersonStatus::ACTIVE,
        ]);
        $profile = StudentProfile::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'student_code' => 'STD-2026-003',
            'department_id' => $dept->id,
            'education_level' => 'VOC_CERT',
            'year_level' => 3,
            'academic_status' => StudentAcademicStatus::GRADUATED,
        ]);

        // 1. Authorization: student.archive required
        $this->actingAs($unauth, 'web')->patchJson("/api/v1/students/{$profile->uuid}/archive")->assertStatus(403);

        // 2. Archive student
        $archiveResp = $this->actingAs($archiver, 'web')->patchJson("/api/v1/students/{$profile->uuid}/archive");
        $archiveResp->assertStatus(200);

        // 3. Invariant: Person.status becomes ARCHIVED; StudentProfile.academic_status preserved
        $person->refresh();
        $profile->refresh();
        $this->assertEquals(PersonStatus::ARCHIVED, $person->status);
        $this->assertEquals(StudentAcademicStatus::GRADUATED, $profile->academic_status);

        // 4. Audit log emitted
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_ARCHIVED->value,
            'entity_type' => 'STUDENT',
            'entity_id' => $profile->uuid,
        ]);
    }

    // =========================================================================
    // SECTION E: PHOTO STORAGE MANAGEMENT (STD-010)
    // =========================================================================

    public function test_student_photo_upload_delete_and_security_invariants(): void
    {
        $updater = $this->createPermittedUser(['student.view', 'student.update'], 'photo_admin');

        $dept = Department::create(['uuid' => (string) Str::uuid(), 'code' => 'D-ART', 'name' => 'Arts', 'status' => AcademicStatus::ACTIVE]);
        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'person_code' => 'P-DIANA-01',
            'person_type' => PersonType::STUDENT,
            'first_name' => 'Diana',
            'last_name' => 'Prince',
            'status' => PersonStatus::ACTIVE,
        ]);
        $profile = StudentProfile::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'student_code' => 'STD-2026-004',
            'department_id' => $dept->id,
            'education_level' => 'VOC_CERT',
            'year_level' => 1,
            'academic_status' => StudentAcademicStatus::ACTIVE,
        ]);

        // 1. Security: Reject non-image/dangerous formats (e.g. php/svg/executable)
        $maliciousFile = UploadedFile::fake()->create('malicious.php', 10, 'text/x-php');
        $this->actingAs($updater, 'web')->postJson("/api/v1/students/{$profile->uuid}/photo", [
            'photo' => $maliciousFile,
        ])->assertStatus(422);

        // 2. Valid image upload (JPEG fake)
        $validPhoto = UploadedFile::fake()->create('student_diana.jpg', 100, 'image/jpeg');
        $uploadResp = $this->actingAs($updater, 'web')->postJson("/api/v1/students/{$profile->uuid}/photo", [
            'photo' => $validPhoto,
        ]);
        $uploadResp->assertStatus(200);

        $person->refresh();
        $this->assertNotNull($person->primary_photo);
        $this->assertStringContainsString('photos/students/', $person->primary_photo);
        Storage::disk('public')->assertExists($person->primary_photo);

        // Invariant: Audit log recorded
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_PHOTO_UPDATED->value,
            'entity_type' => 'STUDENT',
            'entity_id' => $profile->uuid,
        ]);

        // 3. Delete Photo
        $deleteResp = $this->actingAs($updater, 'web')->deleteJson("/api/v1/students/{$profile->uuid}/photo");
        $deleteResp->assertStatus(200);

        $person->refresh();
        $this->assertNull($person->primary_photo);

        // Invariant: Audit log recorded
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_PHOTO_DELETED->value,
            'entity_type' => 'STUDENT',
            'entity_id' => $profile->uuid,
        ]);
    }

    // =========================================================================
    // SECTION F: CSV IMPORT & CONFLICT HANDLING LIFECYCLE (STD-011..014)
    // =========================================================================

    public function test_student_import_validation_conflict_modes_and_queued_execution(): void
    {
        Queue::fake();

        $importer = $this->createPermittedUser(['student.import', 'student.view'], 'importer');
        $dept = Department::create(['uuid' => (string) Str::uuid(), 'code' => 'D-ENG', 'name' => 'Engineering', 'status' => AcademicStatus::ACTIVE]);
        $prog = Program::create(['uuid' => (string) Str::uuid(), 'department_id' => $dept->id, 'code' => 'P-CS', 'name' => 'Computer Science', 'education_level' => 'VOC_CERT', 'status' => AcademicStatus::ACTIVE]);

        // Existing student for conflict testing
        $existingPerson = Person::create([
            'uuid' => (string) Str::uuid(),
            'person_code' => 'P-EXIST-01',
            'person_type' => PersonType::STUDENT,
            'first_name' => 'Existing',
            'last_name' => 'Student',
            'status' => PersonStatus::ACTIVE,
        ]);
        StudentProfile::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $existingPerson->id,
            'student_code' => 'STD-EXIST-01',
            'department_id' => $dept->id,
            'program_id' => $prog->id,
            'education_level' => 'VOC_CERT',
            'year_level' => 1,
            'academic_status' => StudentAcademicStatus::ACTIVE,
        ]);

        // 1. Upload CSV with 3 rows:
        // - Row 1: Valid new student
        // - Row 2: Conflict with existing student code
        $csvContent = "student_code,first_name,last_name,department_code,program_code,education_level,year_level\n"
            ."STD-NEW-01,Edward,Elric,D-ENG,P-CS,VOC_CERT,1\n"
            ."STD-EXIST-01,Existing,Student,D-ENG,P-CS,VOC_CERT,1\n";

        $file = UploadedFile::fake()->createWithContent('students.csv', $csvContent);

        $uploadResp = $this->actingAs($importer, 'web')->postJson('/api/v1/students/import', [
            'file' => $file,
            'conflict_mode' => 'SKIP_EXISTING',
        ]);

        $uploadResp->assertStatus(201);
        $jobUuid = $uploadResp->json('data.uuid');

        // 2. Verify ImportJob created with validation stats
        $job = ImportJob::where('uuid', $jobUuid)->firstOrFail();
        $this->assertEquals(2, $job->total_rows);
        $this->assertEquals('SKIP_EXISTING', $job->conflict_mode->value);

        // 3. Preview endpoint reflects rows and conflicts
        $previewResp = $this->actingAs($importer, 'web')->getJson("/api/v1/students/imports/{$jobUuid}/preview");
        $previewResp->assertStatus(200)
            ->assertJsonStructure([
                'job',
                'summary' => [
                    'total_rows',
                    'valid_rows',
                    'invalid_rows',
                    'conflict_rows',
                    'identity_mismatches',
                    'conflict_mode',
                    'is_ready_for_execution',
                ],
                'sample_rows',
                'sample_errors',
            ]);

        // 4. Process execution dispatches queued job with stable conflict_mode
        $processResp = $this->actingAs($importer, 'web')->postJson("/api/v1/students/imports/{$jobUuid}/process");
        $processResp->assertStatus(202);

        Queue::assertPushed(ProcessStudentImportJob::class, function ($jobInstance) use ($job) {
            return $jobInstance->importJob->id === $job->id;
        });

        // 5. Invariant: conflict_mode is persisted and cannot be overridden at process time
        $this->assertEquals('SKIP_EXISTING', $job->fresh()->conflict_mode->value);
    }

    // =========================================================================
    // SECTION G: ARCHITECTURE, SECURITY & MODULE 03 BOUNDARY INVARIANTS
    // =========================================================================

    public function test_module_02_golden_architecture_and_security_invariants(): void
    {
        // 1. Unauthenticated requests are strictly rejected with 401
        $this->getJson('/api/v1/students')->assertStatus(401);
        $this->postJson('/api/v1/students', [])->assertStatus(401);
        $this->getJson('/api/v1/departments')->assertStatus(401);

        // 2. Public identity rule: Int integer identifiers must not be accepted on UUID route bindings
        $user = $this->createPermittedUser(['student.view']);
        $this->actingAs($user, 'web')->getJson('/api/v1/students/1')->assertStatus(404);
        $this->actingAs($user, 'web')->getJson('/api/v1/departments/1')->assertStatus(404);

        // 3. Golden rule: Module 02 contains ZERO door access control endpoints or decisions
        $this->assertFalse(RouteFacade::has('api.v1.doors.access'));
        $this->assertFalse(RouteFacade::has('api.v1.access.decisions'));

        // 4. Golden rule: Module 02 contains ZERO biometric face profiles
        $this->assertDatabaseCount('face_profiles', 0);
    }
}
