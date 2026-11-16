<?php

namespace Tests\Feature;

use App\Enums\GuardianRelation;
use App\Enums\PersonType;
use App\Enums\StaffEmploymentStatus;
use App\Enums\StatusChangeSource;
use App\Enums\VisitStatus;
use App\Models\GuardianLink;
use App\Models\Person;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\StaffStatusHistory;
use App\Models\StudentProfile;
use App\Models\Visit;
use App\Models\VisitorProfile;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PPL-002 (Module 09): staff, guardian, visitor and visit tables, models and
 * permissions (docs/12-module-09-people-visitor.md).
 */
class PeopleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_profile_links_one_to_one_to_a_person_with_status_history(): void
    {
        $staff = StaffProfile::factory()->create(['staff_code' => 'T00001']);
        $person = $staff->person;

        $this->assertSame(PersonType::STAFF, $person->person_type);
        $this->assertTrue($person->staffProfile->is($staff));
        $this->assertSame(StaffEmploymentStatus::ACTIVE, $staff->employment_status);
        $this->assertNotEmpty($staff->uuid);

        $history = StaffStatusHistory::create([
            'staff_profile_id' => $staff->id,
            'old_status' => StaffEmploymentStatus::ACTIVE,
            'new_status' => StaffEmploymentStatus::LEAVE,
            'effective_from' => now(),
            'source' => StatusChangeSource::MANUAL,
        ]);
        $this->assertTrue($staff->statusHistories()->first()->is($history));

        $this->expectException(QueryException::class);
        StaffProfile::factory()->create(['person_id' => $person->id, 'staff_code' => 'T00002']);
    }

    public function test_staff_code_is_unique(): void
    {
        StaffProfile::factory()->create(['staff_code' => 'T12345']);

        $this->expectException(QueryException::class);
        StaffProfile::factory()->create(['staff_code' => 'T12345']);
    }

    public function test_staff_employment_status_is_constrained(): void
    {
        $staff = StaffProfile::factory()->create();

        // Bypass the enum cast: the database itself must reject a student status.
        $this->expectException(QueryException::class);
        DB::table('staff_profiles')->where('id', $staff->id)->update(['employment_status' => 'GRADUATED']);
    }

    public function test_a_guardian_links_once_to_each_student(): void
    {
        $link = GuardianLink::factory()->create(['relation' => GuardianRelation::MOTHER]);

        $this->assertSame(PersonType::PARENT, $link->guardian->person_type);
        $this->assertNotNull($link->student->studentProfile);
        $this->assertTrue($link->guardian->guardianOf()->first()->is($link));
        $this->assertTrue($link->student->guardians()->first()->is($link));

        $this->expectException(QueryException::class);
        GuardianLink::factory()->create([
            'guardian_person_id' => $link->guardian_person_id,
            'student_person_id' => $link->student_person_id,
        ]);
    }

    public function test_visitor_profile_keeps_only_the_last_four_id_characters(): void
    {
        $visitor = VisitorProfile::factory()->create(['id_document_last4' => '1234']);
        $this->assertSame(PersonType::VISITOR, $visitor->person->person_type);
        $this->assertTrue($visitor->person->visitorProfile->is($visitor));
        $this->assertSame('1234', $visitor->fresh()->id_document_last4);
        // No column can hold a full document number (the API rejects more than 4 characters, PPL-006).
        $this->assertNotContains('id_document_number', Schema::getColumnListing('visitor_profiles'));
    }

    public function test_a_visit_belongs_to_a_visitor_and_a_site(): void
    {
        $host = StaffProfile::factory()->create()->person;
        $visit = Visit::factory()->create(['host_person_id' => $host->id]);

        $this->assertSame(VisitStatus::SCHEDULED, $visit->status);
        $this->assertTrue($visit->visitor->visits()->first()->is($visit));
        $this->assertTrue($visit->host->is($host));
        $this->assertNotNull($visit->site);

        $this->expectException(QueryException::class);
        DB::table('visits')->where('id', $visit->id)->update(['status' => 'APPROVED']);
    }

    public function test_a_person_with_history_cannot_be_hard_deleted(): void
    {
        $staff = StaffProfile::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('persons')->where('id', $staff->person_id)->delete();
    }

    public function test_fresh_install_grants_people_permissions_by_role(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);

        $codes = fn (string $role) => Role::where('code', $role)->firstOrFail()->permissions->pluck('code')->all();

        $this->assertContains('staff.import', $codes('SUPER_ADMIN'));
        $this->assertContains('visit.manage', $codes('SYSTEM_ADMIN'));
        $this->assertContains('visit.manage', $codes('SECURITY_OFFICER'));
        $this->assertContains('visitor.manage', $codes('SECURITY_OFFICER'));
        $this->assertNotContains('staff.update', $codes('SECURITY_OFFICER'));
        $this->assertContains('guardian.view', $codes('DISCIPLINE_OFFICER'));
        $this->assertNotContains('visit.manage', $codes('DISCIPLINE_OFFICER'));
        $this->assertNotContains('staff.view', $codes('EXECUTIVE_VIEWER'));
    }

    public function test_migration_grants_people_permissions_on_an_existing_role_matrix(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);

        // Simulate an install seeded before Module 09: drop the new permissions.
        $codes = ['staff.view', 'staff.create', 'staff.update', 'staff.status.change', 'staff.import',
            'guardian.view', 'guardian.manage', 'visitor.view', 'visitor.manage', 'visit.view', 'visit.manage'];
        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        $migration = require database_path('migrations/2026_11_16_700015_add_people_permissions.php');
        $migration->up();
        $migration->up(); // idempotent

        $security = Role::where('code', 'SECURITY_OFFICER')->firstOrFail()->permissions->pluck('code')->all();
        $this->assertContains('visit.manage', $security);
        $this->assertNotContains('staff.create', $security);
        $this->assertCount(11, DB::table('permissions')->whereIn('code', $codes)->get());
        $this->assertCount(11, Role::where('code', 'SYSTEM_ADMIN')->firstOrFail()->permissions()->whereIn('code', $codes)->get());
    }

    public function test_student_profiles_are_untouched(): void
    {
        $student = StudentProfile::factory()->create();
        $this->assertNull($student->person->staffProfile);
        $this->assertNull($student->person->visitorProfile);
        $this->assertSame(0, Person::whereHas('visits')->count());
    }
}
