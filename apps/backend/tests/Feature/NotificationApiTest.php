<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EVT-012 — notification inbox API (docs/02-api-contract.md §17).
 * Always scoped to the authenticated user; no shared/cross-user access.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'PLAIN_'.uniqid(), 'name' => 'Plain', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    public function test_lists_only_the_authenticated_users_own_notifications(): void
    {
        $me = $this->activeUser();
        $someoneElse = $this->activeUser();

        Notification::factory()->for($me)->create(['title' => 'Mine']);
        Notification::factory()->for($someoneElse)->create(['title' => 'Not mine']);

        $res = $this->actingAs($me, 'web')->getJson('/api/v1/notifications');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_unread_only_filter(): void
    {
        $me = $this->activeUser();
        Notification::factory()->for($me)->create(['read_at' => now()]);
        Notification::factory()->for($me)->create(['read_at' => null]);

        $res = $this->actingAs($me, 'web')->getJson('/api/v1/notifications?unread_only=1');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.read_at', null);
    }

    public function test_meta_reports_the_unread_count_regardless_of_the_current_filter(): void
    {
        $me = $this->activeUser();
        Notification::factory()->for($me)->create(['read_at' => now()]);
        Notification::factory()->for($me)->count(2)->create(['read_at' => null]);

        $res = $this->actingAs($me, 'web')->getJson('/api/v1/notifications');

        $res->assertOk();
        $res->assertJsonPath('meta.unread_count', 2);
    }

    public function test_marks_a_single_notification_read(): void
    {
        $me = $this->activeUser();
        $notification = Notification::factory()->for($me)->create(['read_at' => null]);

        $res = $this->actingAs($me, 'web')->patchJson("/api/v1/notifications/{$notification->uuid}/read");

        $res->assertOk();
        $res->assertJsonPath('data.uuid', $notification->uuid);
        $this->assertNotNull($res->json('data.read_at'));

        $notification->refresh();
        $this->assertNotNull($notification->read_at);
    }

    public function test_marking_read_is_idempotent(): void
    {
        $me = $this->activeUser();
        $notification = Notification::factory()->for($me)->create(['read_at' => now()->subHour()]);
        $originalReadAt = $notification->read_at->toISOString();

        $this->actingAs($me, 'web')->patchJson("/api/v1/notifications/{$notification->uuid}/read")->assertOk();

        $notification->refresh();
        $this->assertSame($originalReadAt, $notification->read_at->toISOString());
    }

    public function test_cannot_mark_another_users_notification_read(): void
    {
        $me = $this->activeUser();
        $someoneElse = $this->activeUser();
        $notification = Notification::factory()->for($someoneElse)->create(['read_at' => null]);

        $this->actingAs($me, 'web')->patchJson("/api/v1/notifications/{$notification->uuid}/read")->assertStatus(404);

        $notification->refresh();
        $this->assertNull($notification->read_at);
    }

    public function test_read_all_marks_every_unread_notification_for_the_user_only(): void
    {
        $me = $this->activeUser();
        $someoneElse = $this->activeUser();
        Notification::factory()->for($me)->count(3)->create(['read_at' => null]);
        $othersUnread = Notification::factory()->for($someoneElse)->create(['read_at' => null]);

        $res = $this->actingAs($me, 'web')->postJson('/api/v1/notifications/read-all');

        $res->assertOk();
        $res->assertJsonPath('data.updated_count', 3);

        $this->assertSame(0, Notification::where('user_id', $me->id)->whereNull('read_at')->count());
        $othersUnread->refresh();
        $this->assertNull($othersUnread->read_at);
    }
}
