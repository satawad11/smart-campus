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
use App\Models\StudentStatusHistory;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RPT-002 — person timeline (docs/02-api-contract.md §15
 * GET /persons/{uuid}/timeline). Merges recognition events and academic
 * status changes into one chronological feed.
 */
class PersonTimelineApiTest extends TestCase
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
        $person = Person::factory()->create();
        $this->getJson("/api/v1/persons/{$person->uuid}/timeline")->assertStatus(401);
    }

    public function test_requires_report_view_permission(): void
    {
        $user = $this->userWith([]);
        $person = Person::factory()->create();
        $this->actingAs($user, 'web')->getJson("/api/v1/persons/{$person->uuid}/timeline")->assertStatus(403);
    }

    public function test_merges_events_and_status_changes_in_chronological_order(): void
    {
        $user = $this->userWith(['report.view']);
        $person = Person::factory()->create();
        $profile = StudentProfile::factory()->for($person)->create();

        $oldEvent = AccessEvent::factory()->create(['person_id' => $person->id, 'occurred_at' => now()->subDays(3)]);
        AccessDecision::factory()->for($oldEvent, 'event')->create();

        $statusChange = StudentStatusHistory::factory()->create([
            'student_profile_id' => $profile->id,
            'old_status' => 'ACTIVE',
            'new_status' => 'SUSPENDED',
            'effective_from' => now()->subDays(2),
            'reason' => 'Disciplinary action',
        ]);

        $recentEvent = AccessEvent::factory()->create(['person_id' => $person->id, 'occurred_at' => now()->subDay()]);
        AccessDecision::factory()->for($recentEvent, 'event')->create(['decision' => 'REVIEW']);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/persons/{$person->uuid}/timeline");

        $res->assertOk();
        $res->assertJsonCount(3, 'data');
        // Newest first.
        $res->assertJsonPath('data.0.type', 'EVENT');
        $res->assertJsonPath('data.0.event.uuid', $recentEvent->uuid);
        $res->assertJsonPath('data.1.type', 'STATUS_CHANGE');
        $res->assertJsonPath('data.1.status_change.new_status', 'SUSPENDED');
        $res->assertJsonPath('data.1.status_change.reason', 'Disciplinary action');
        $res->assertJsonPath('data.2.event.uuid', $oldEvent->uuid);
    }

    public function test_a_person_with_no_student_profile_only_shows_events(): void
    {
        $user = $this->userWith(['report.view']);
        $person = Person::factory()->create();
        $event = AccessEvent::factory()->create(['person_id' => $person->id]);
        AccessDecision::factory()->for($event, 'event')->create();

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/persons/{$person->uuid}/timeline");

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.type', 'EVENT');
    }

    public function test_events_never_leak_across_persons(): void
    {
        $user = $this->userWith(['report.view']);
        $person = Person::factory()->create();
        $otherPerson = Person::factory()->create();

        $event = AccessEvent::factory()->create(['person_id' => $person->id]);
        AccessDecision::factory()->for($event, 'event')->create();
        $otherEvent = AccessEvent::factory()->create(['person_id' => $otherPerson->id]);
        AccessDecision::factory()->for($otherEvent, 'event')->create();

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/persons/{$person->uuid}/timeline");

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.event.uuid', $event->uuid);
    }

    public function test_date_range_filters_both_sources(): void
    {
        $user = $this->userWith(['report.view']);
        $person = Person::factory()->create();
        $profile = StudentProfile::factory()->for($person)->create();

        AccessEvent::factory()->create(['person_id' => $person->id, 'occurred_at' => now()->subDays(10)]);
        StudentStatusHistory::factory()->create([
            'student_profile_id' => $profile->id,
            'effective_from' => now()->subDays(10),
        ]);

        $recentEvent = AccessEvent::factory()->create(['person_id' => $person->id, 'occurred_at' => now()]);
        AccessDecision::factory()->for($recentEvent, 'event')->create();

        $res = $this->actingAs($user, 'web')->getJson(
            '/api/v1/persons/'.$person->uuid.'/timeline?from='.now()->subDay()->toISOString()
        );

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.event.uuid', $recentEvent->uuid);
    }

    public function test_unknown_person_uuid_returns_404(): void
    {
        $user = $this->userWith(['report.view']);
        $this->actingAs($user, 'web')
            ->getJson('/api/v1/persons/00000000-0000-0000-0000-000000000000/timeline')
            ->assertStatus(404);
    }
}
