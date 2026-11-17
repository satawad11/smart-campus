<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RPT-001 — historical search API (docs/10-module-06-reporting.md
 * "Historical Search", docs/02-api-contract.md §15 GET /history/search).
 * Reuses EventSearchQuery (EVT-008's filters, extracted so both endpoints
 * share the exact same semantics) plus keyword — the one genuinely new
 * filter this module adds.
 */
class HistorySearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'RPT_'.uniqid(), 'name' => 'Reporter', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/history/search')->assertStatus(401);
    }

    public function test_requires_report_view_permission_not_event_view(): void
    {
        $user = $this->userWith(['event.view']); // event.view alone must not be enough
        $this->actingAs($user, 'web')->getJson('/api/v1/history/search')->assertStatus(403);
    }

    public function test_shares_the_same_filters_as_get_events(): void
    {
        $user = $this->userWith(['report.view']);
        $event = AccessEvent::factory()->create(['event_type' => 'FACE_RECOGNIZED']);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW']);
        AccessEvent::factory()->create(['event_type' => 'UNKNOWN_PERSON']);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/history/search?event_type=FACE_RECOGNIZED');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.uuid', $event->uuid);
    }

    public function test_keyword_matches_person_display_name(): void
    {
        $user = $this->userWith(['report.view']);
        $person = Person::factory()->create(['display_name' => 'Somchai Testcase']);
        $event = AccessEvent::factory()->create(['person_id' => $person->id]);
        AccessDecision::factory()->for($event, 'event')->create();
        $otherEvent = AccessEvent::factory()->create(['person_id' => Person::factory()->create(['display_name' => 'Someone Else'])->id]);
        AccessDecision::factory()->for($otherEvent, 'event')->create();

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/history/search?keyword=Testcase');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.uuid', $event->uuid);
    }

    public function test_keyword_matches_student_code(): void
    {
        $user = $this->userWith(['report.view']);
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['student_code' => 'STU-9999']);
        $event = AccessEvent::factory()->create(['person_id' => $person->id]);
        AccessDecision::factory()->for($event, 'event')->create();

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/history/search?keyword=STU-9999');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
    }

    public function test_history_results_include_person_group_and_current_profile_status(): void
    {
        $user = $this->userWith(['report.view']);
        $person = Person::factory()->create(['person_type' => 'STUDENT', 'status' => 'ACTIVE']);
        StudentProfile::factory()->for($person)->create(['student_code' => 'STU-GROUP-1', 'academic_status' => 'WITHDRAWN']);
        $event = AccessEvent::factory()->create(['person_id' => $person->id]);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);

        $this->actingAs($user, 'web')->getJson('/api/v1/history/search')
            ->assertOk()
            ->assertJsonPath('data.0.person.person_type', 'STUDENT')
            ->assertJsonPath('data.0.person.status', 'WITHDRAWN')
            ->assertJsonPath('data.0.person.academic_status', 'WITHDRAWN')
            ->assertJsonPath('data.0.person.name', $person->displayLabel())
            ->assertJsonPath('data.0.current_decision.decision', 'REVIEW');
    }

    public function test_keyword_with_no_match_returns_an_empty_result_not_everything(): void
    {
        $user = $this->userWith(['report.view']);
        $event = AccessEvent::factory()->create(['person_id' => Person::factory()->create()->id]);
        AccessDecision::factory()->for($event, 'event')->create();

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/history/search?keyword=no-such-person-xyz');

        $res->assertOk();
        $res->assertJsonCount(0, 'data');
    }

    public function test_no_filters_returns_all_events_the_user_can_see(): void
    {
        $user = $this->userWith(['report.view']);
        AccessEvent::factory()->count(3)->create()->each(
            fn ($event) => AccessDecision::factory()->for($event, 'event')->create(),
        );

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/history/search');

        $res->assertOk();
        $res->assertJsonCount(3, 'data');
    }
}
