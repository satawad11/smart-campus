<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Jobs\RunRmsSyncJob;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Program;
use App\Models\RmsLink;
use App\Models\RmsSyncRun;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\StudentStatusHistory;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RMS -> platform sync (ACR-INT-01). Fixtures follow the shapes seen in the
 * 2026-11-03 live preview (grade "ปวช.1", empty group department) with
 * invented people; no real data.
 */
class RmsSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $groups = [];

    /** @var list<array<string, mixed>> */
    private array $students = [];

    /** @var list<array<string, mixed>> */
    private array $staff = [];

    private ?string $rmsError = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        config([
            'rms.url' => 'https://rms.example.test/api_center.php',
            'rms.key' => 'rmsk_test0000_0000000000000000000000000000000000000000000000',
            'rms.major_departments' => ['ช่างยนต์' => 'แผนกวิชาช่างยนต์', 'เทคนิคเครื่องกล' => 'แผนกวิชาช่างยนต์'],
        ]);

        $this->groups = [
            ['id' => 'g1', 'short_name' => 'ชย.1/1', 'name' => 'ปวช.1 ช่างยนต์ 1', 'level' => 'ปวช.', 'grade' => 'ปวช.1', 'major' => 'ช่างยนต์', 'department' => '', 'year' => 2569, 'advisors' => []],
            ['id' => 'g2', 'short_name' => 'ทค.2/1', 'name' => 'ปวส.2 เทคนิคเครื่องกล 1', 'level' => 'ปวส.', 'grade' => 'ปวส.2', 'major' => 'เทคนิคเครื่องกล', 'department' => '', 'year' => 2568, 'advisors' => []],
            ['id' => 'g3', 'short_name' => 'บช.1/1', 'name' => 'ปวช.1 การบัญชี 1', 'level' => 'ปวช.', 'grade' => 'ปวช.1', 'major' => 'การบัญชี', 'department' => '', 'year' => 2569, 'advisors' => []],
        ];
        $this->students = [
            ['id' => '6920100001', 'prefix' => 'นาย', 'name' => 'หนึ่ง', 'surname' => 'ทดสอบ', 'group_id' => 'g1', 'number' => 1],
            ['id' => '6920100002', 'prefix' => 'นางสาว', 'name' => 'สอง', 'surname' => 'ทดสอบ', 'group_id' => 'g1', 'number' => 2],
            ['id' => '6830200001', 'prefix' => 'นาย', 'name' => 'สาม', 'surname' => 'ทดสอบ', 'group_id' => 'g2', 'number' => 1],
            ['id' => '6920300001', 'prefix' => 'นางสาว', 'name' => 'สี่', 'surname' => 'ทดสอบ', 'group_id' => 'g3', 'number' => 1],
        ];
        $this->staff = [
            ['id' => 's_aaaa', 'name' => 'นายสมมุติ', 'surname' => 'ทดสอบ', 'position' => 'ครู', 'departments' => []],
            ['id' => 's_bbbb', 'name' => 'นางสาวสมมุติ', 'surname' => 'ทดลอง', 'position' => 'ไม่มี', 'departments' => []],
        ];

        Http::fake(function (Request $request) {
            if ($this->rmsError !== null) {
                return Http::response(['ok' => false, 'error' => $this->rmsError, 'error_msg' => 'x'], 403);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $items = match ($q['res'] ?? '') {
                'groups' => $this->groups,
                'students' => $this->students,
                'staff' => $this->staff,
                default => null,
            };
            if ($items === null) {
                return Http::response(['ok' => false, 'error' => 'not_found', 'error_msg' => 'x'], 404);
            }

            return Http::response(['ok' => true, 'api' => 'rms', 'v' => 1, 'res' => $q['res'], 'page' => 1, 'limit' => 500, 'has_more' => false, 'items' => $items]);
        });
    }

    private function sync(bool $dryRun = false): RmsSyncRun
    {
        Artisan::call('rms:sync', $dryRun ? ['--dry-run' => true] : []);

        return RmsSyncRun::latest('id')->firstOrFail();
    }

    /** @return array<string, int> */
    private function issueCounts(RmsSyncRun $run): array
    {
        return array_column($run->issues ?? [], 'count', 'code');
    }

    public function test_dry_run_reports_everything_and_writes_nothing(): void
    {
        $run = $this->sync(dryRun: true);

        $this->assertSame(RmsSyncRun::COMPLETED, $run->status);
        $this->assertTrue($run->dry_run);
        $this->assertSame(4, $run->counts['students_created']);
        $this->assertSame(2, $run->counts['staff_created']);
        $this->assertSame(3, $run->counts['classrooms_created']);
        $this->assertSame(2, $run->counts['departments_created']);
        $this->assertSame(1, $this->issueCounts($run)['MAJOR_NOT_MAPPED']);

        $this->assertSame(0, StudentProfile::count());
        $this->assertSame(0, Person::count());
        $this->assertSame(0, Department::count());
        $this->assertSame(0, RmsLink::count());
    }

    public function test_sync_creates_academic_structure_students_and_staff(): void
    {
        $run = $this->sync();

        $this->assertSame(RmsSyncRun::COMPLETED, $run->status);
        // Majors map to departments; an unmapped major gets "แผนกวิชา<major>".
        $this->assertEqualsCanonicalizing(['แผนกวิชาช่างยนต์', 'แผนกวิชาการบัญชี'], Department::pluck('name')->all());
        $this->assertSame(3, Program::count());

        $classroom = Classroom::where('code', 'ทค.2/1')->firstOrFail();
        $this->assertSame('2568', (string) $classroom->academic_year);
        $this->assertSame(2, (int) $classroom->year_level);

        $student = StudentProfile::with('person')->where('student_code', '6830200001')->firstOrFail();
        $this->assertSame('HIGH_VOC_CERT', $student->education_level);
        $this->assertSame(2, (int) $student->year_level);
        $this->assertSame($classroom->id, $student->classroom_id);
        $this->assertSame('ACTIVE', $student->academic_status->value);
        $this->assertSame('สาม', $student->person->first_name);
        $this->assertSame('นายสาม ทดสอบ', $student->person->display_name);
        $this->assertSame('SIS_SYNC', StudentStatusHistory::where('student_profile_id', $student->id)->firstOrFail()->source->value);

        $teacher = Person::find(RmsLink::where('resource', 'STAFF')->where('external_id', 's_aaaa')->value('entity_id'));
        $this->assertSame('TEACHER', $teacher->person_type->value);
        $this->assertSame('สมมุติ', $teacher->first_name);
        $this->assertSame('นายสมมุติ ทดสอบ', $teacher->display_name);
        $staff = Person::find(RmsLink::where('resource', 'STAFF')->where('external_id', 's_bbbb')->value('entity_id'));
        $this->assertSame('STAFF', $staff->person_type->value);
        $this->assertSame(0, User::whereIn('person_id', [$teacher->id, $staff->id])->count());

        $this->assertSame(1, AuditLog::where('action', AuditAction::RMS_SYNC_COMPLETED->value)->count());
        $this->assertSame(4, AuditLog::where('action', AuditAction::STUDENT_CREATED->value)->count());
    }

    public function test_second_run_changes_nothing(): void
    {
        $this->sync();
        $counts = [StudentProfile::count(), Person::count(), Classroom::count(), Program::count(), Department::count()];

        $run = $this->sync();

        $this->assertSame($counts, [StudentProfile::count(), Person::count(), Classroom::count(), Program::count(), Department::count()]);
        $this->assertSame(4, $run->counts['students_unchanged']);
        $this->assertSame(2, $run->counts['staff_unchanged']);
        $this->assertArrayNotHasKey('students_updated', $run->counts);
        $this->assertArrayNotHasKey('classrooms_updated', $run->counts);
    }

    public function test_existing_student_is_matched_by_code_and_status_is_never_changed(): void
    {
        $this->sync();
        $profile = StudentProfile::where('student_code', '6920100001')->firstOrFail();
        $profile->update(['academic_status' => 'WITHDRAWN']);

        // RMS moves the student to another group and corrects the surname.
        $this->students[0]['group_id'] = 'g3';
        $this->students[0]['surname'] = 'ทดสอบใหม่';
        $run = $this->sync();

        $profile->refresh();
        $this->assertSame('WITHDRAWN', $profile->academic_status->value);
        $this->assertSame(Classroom::where('code', 'บช.1/1')->value('id'), $profile->classroom_id);
        $this->assertSame('ทดสอบใหม่', $profile->person->last_name);
        $this->assertSame(1, $run->counts['students_updated']);
        $this->assertSame(1, $this->issueCounts($run)['STUDENT_STATUS_REVIEW']);
        $this->assertSame(1, StudentProfile::where('student_code', '6920100001')->count());
    }

    public function test_students_missing_from_rms_are_reported_not_removed(): void
    {
        $this->sync();
        array_pop($this->students);

        $run = $this->sync();

        $profile = StudentProfile::where('student_code', '6920300001')->firstOrFail();
        $this->assertSame('ACTIVE', $profile->academic_status->value);
        $this->assertSame(1, $run->counts['students_missing_from_rms']);
        $this->assertSame(['6920300001'], collect($run->issues)->firstWhere('code', 'STUDENT_MISSING_FROM_RMS')['items']);
        $this->assertNotNull(RmsLink::where('external_id', '6920300001')->value('missing_since'));
    }

    public function test_a_run_with_too_few_students_writes_nothing(): void
    {
        $this->sync();
        $this->students = [$this->students[0]];
        $this->students[0]['surname'] = 'ไม่ควรถูกเขียน';

        $run = $this->sync();

        $this->assertSame(RmsSyncRun::FAILED, $run->status);
        $this->assertStringContainsString('too_few_students', $run->error);
        $this->assertSame('ทดสอบ', StudentProfile::where('student_code', '6920100001')->firstOrFail()->person->last_name);
        $this->assertSame(0, RmsLink::whereNotNull('missing_since')->count());
    }

    public function test_rms_errors_fail_the_run(): void
    {
        $this->rmsError = 'ip';

        $this->assertSame(1, Artisan::call('rms:sync'));
        $run = RmsSyncRun::latest('id')->firstOrFail();

        $this->assertSame(RmsSyncRun::FAILED, $run->status);
        $this->assertStringStartsWith('[ip]', $run->error);
        $this->assertSame(1, AuditLog::where('action', AuditAction::RMS_SYNC_FAILED->value)->count());
    }

    public function test_api_requires_student_sync_and_queues_runs(): void
    {
        Bus::fake();
        $this->sync(dryRun: true);
        $run = RmsSyncRun::firstOrFail();

        $viewer = $this->userWith(['student.view', 'student.import']);
        $this->actingAs($viewer, 'web')->getJson('/api/v1/integrations/rms/sync-runs')->assertForbidden();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/integrations/rms/sync-runs', ['dry_run' => true])->assertForbidden();

        $admin = $this->userWith(['student.sync']);
        $this->actingAs($admin, 'web')->getJson('/api/v1/integrations/rms/sync-runs')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $run->uuid)
            ->assertJsonPath('data.0.issues', null)
            ->assertJsonPath('meta.configured', true)
            ->assertJsonPath('meta.schedule_enabled', false);
        $this->actingAs($admin, 'web')->getJson("/api/v1/integrations/rms/sync-runs/{$run->uuid}")
            ->assertOk()
            ->assertJsonPath('data.counts.students_created', 4)
            ->assertJsonFragment(['code' => 'MAJOR_NOT_MAPPED', 'count' => 1, 'items' => ['การบัญชี -> แผนกวิชาการบัญชี']]);
        $this->actingAs($admin, 'web')->postJson('/api/v1/integrations/rms/sync-runs', ['dry_run' => false])
            ->assertStatus(202);

        Bus::assertDispatched(RunRmsSyncJob::class, fn (RunRmsSyncJob $job) => $job->dryRun === false && $job->userId === $admin->id);
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'RMS_'.uniqid(), 'name' => 'RMS test', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }
}
