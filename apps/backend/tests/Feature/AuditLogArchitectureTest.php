<?php

namespace Tests\Feature;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuditLogArchitectureTest extends TestCase
{
    public function test_audit_logs_have_no_update_delete_or_purge_routes(): void
    {
        $routes = Route::getRoutes();

        foreach ($routes as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'api/v1/audit-logs') || str_starts_with($uri, 'audit-logs')) {
                $methods = $route->methods();
                $this->assertNotContains('DELETE', $methods, "Audit log route {$uri} must not support DELETE.");
                $this->assertNotContains('PUT', $methods, "Audit log route {$uri} must not support PUT.");
                $this->assertNotContains('PATCH', $methods, "Audit log route {$uri} must not support PATCH.");
            }
        }
    }

    public function test_audit_log_resource_never_exposes_internal_database_ids(): void
    {
        $actor = new User;
        $actor->id = 9999;
        $actor->uuid = 'actor-public-uuid';
        $actor->username = 'actor_user';

        $log = new AuditLog;
        $log->id = 12345;
        $log->uuid = 'log-public-uuid';
        $log->user_id = 9999;
        $log->action = 'USER_CREATED';
        $log->module = 'AUTH';
        $log->entity_type = 'USER';
        $log->entity_id = 'subject-public-uuid';
        $log->old_values = null;
        $log->new_values = ['username' => 'test'];
        $log->metadata = null;
        $log->ip_address = '127.0.0.1';
        $log->user_agent = 'Agent/1.0';
        $log->request_id = 'req-123';
        $log->created_at = now();
        $log->setRelation('actor', $actor);

        $resourceArray = (new AuditLogResource($log))->resolve();

        $this->assertArrayNotHasKey('id', $resourceArray);
        $this->assertArrayNotHasKey('user_id', $resourceArray);
        $this->assertEquals('log-public-uuid', $resourceArray['uuid']);
        $this->assertEquals('actor-public-uuid', $resourceArray['actor']['uuid']);
        $this->assertArrayNotHasKey('id', $resourceArray['actor']);
    }

    public function test_audit_log_model_disables_updated_at_timestamp(): void
    {
        $log = new AuditLog;
        $this->assertNull($log->getUpdatedAtColumn(), 'AuditLog must not maintain updated_at timestamp.');
    }
}
