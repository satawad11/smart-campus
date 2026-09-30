<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Assign or revoke a role with GLOBAL / SITE / ZONE scope (V1 audit A-01).
 *
 * The V1 API contract has no user-role assignment endpoint and no code path
 * wrote user_roles, so operators could be created but never granted a role.
 * This command writes the existing user_roles schema (database blueprint)
 * and is audited. Scope semantics are enforced by PermissionService.
 */
class AssignRoleCommand extends Command
{
    protected $signature = 'access:assign-role
                            {username : User login name}
                            {role : Role code, e.g. SECURITY_OFFICER}
                            {--site= : Site code or UUID for SITE scope}
                            {--zone= : Zone code or UUID (requires --site) for ZONE scope}
                            {--until= : Optional expiry (Y-m-d or ISO-8601)}
                            {--revoke : Remove the matching assignment instead}';

    protected $description = 'Assign or revoke a user role with GLOBAL, SITE or ZONE scope (audited)';

    public function handle(AuditService $audit): int
    {
        $user = User::where('username', $this->argument('username'))->first();
        $role = Role::where('code', strtoupper((string) $this->argument('role')))->first();
        if ($user === null || $role === null) {
            $this->error($user === null ? 'Unknown user.' : 'Unknown role code.');

            return self::FAILURE;
        }

        $site = null;
        $zone = null;
        if ($this->option('site')) {
            $site = Site::where($this->keyColumn($this->option('site')), $this->option('site'))->first();
            if ($site === null) {
                $this->error('Unknown site.');

                return self::FAILURE;
            }
        }
        if ($this->option('zone')) {
            if ($site === null) {
                $this->error('--zone requires --site.');

                return self::FAILURE;
            }
            $zone = Zone::where('site_id', $site->id)
                ->where($this->keyColumn($this->option('zone')), $this->option('zone'))
                ->first();
            if ($zone === null) {
                $this->error('Unknown zone for that site.');

                return self::FAILURE;
            }
        }

        $scope = $zone ? 'ZONE' : ($site ? 'SITE' : 'GLOBAL');
        $match = UserRole::query()
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->where('site_id', $site?->id)
            ->where('zone_id', $zone?->id);

        if ($this->option('revoke')) {
            $removed = $match->delete();
            if ($removed > 0) {
                $this->recordAudit($audit, 'access.role_revoked', $user, $role, $scope, $site, $zone, null);
            }
            $this->info("Removed {$removed} assignment(s).");

            return self::SUCCESS;
        }

        if ($match->exists()) {
            $this->info("{$user->username} already has {$role->code} ({$scope}).");

            return self::SUCCESS;
        }

        $until = $this->option('until') ? Carbon::parse($this->option('until')) : null;
        UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'site_id' => $site?->id,
            'zone_id' => $zone?->id,
            'valid_until' => $until,
            'created_at' => now(),
        ]);
        $this->recordAudit($audit, 'access.role_assigned', $user, $role, $scope, $site, $zone, $until);

        $this->info("Assigned {$role->code} to {$user->username} ({$scope}".($site ? " {$site->code}" : '').($zone ? "/{$zone->code}" : '').').');

        return self::SUCCESS;
    }

    /**
     * Look up by uuid only for UUID-shaped input: PostgreSQL rejects comparing
     * a uuid column with an arbitrary code string (22P02).
     */
    private function keyColumn(string $value): string
    {
        return Str::isUuid($value) ? 'uuid' : 'code';
    }

    private function recordAudit(AuditService $audit, string $action, User $user, Role $role, string $scope, ?Site $site, ?Zone $zone, ?Carbon $until): void
    {
        $audit->record(
            action: $action,
            actor: null,
            subjectType: 'user',
            subjectUuid: $user->uuid,
            oldValues: null,
            newValues: [
                'role' => $role->code,
                'scope' => $scope,
                'site_uuid' => $site?->uuid,
                'zone_uuid' => $zone?->uuid,
                'valid_until' => $until?->toIso8601String(),
            ],
            metadata: ['source' => 'cli'],
            module: 'auth_rbac'
        );
    }
}
