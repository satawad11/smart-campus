<?php

namespace App\Services\Auth;

use App\Enums\PermissionEffect;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserPermission;
use App\Support\Authorization\EffectiveScope;
use App\Support\Authorization\ScopeContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Permission Resolution Service (AUTH-008).
 *
 * Resolves effective user permissions based on:
 * 1. Direct user permission overrides (DENY > ALLOW)
 * 2. Active role permissions with temporal validity (valid_from / valid_until)
 * 3. User account status (only ACTIVE non-locked users receive permissions)
 *
 * Implements strict fail-closed (DENY by default) without hardcoded role bypasses.
 */
class PermissionService
{
    /**
     * Check if a user has a specific effective permission.
     */
    public function hasPermission(User $user, string $permissionCode): bool
    {
        // 1. Account state validation: fail-closed if not active or locked
        if (! $this->isUserActive($user)) {
            return false;
        }

        // 2. Direct user permission overrides (highest priority)
        $override = UserPermission::query()
            ->join('permissions', 'user_permissions.permission_id', '=', 'permissions.id')
            ->where('user_permissions.user_id', $user->id)
            ->where('permissions.code', $permissionCode)
            ->value('user_permissions.effect');

        if ($override !== null) {
            $effect = $override instanceof PermissionEffect ? $override : PermissionEffect::tryFrom($override);

            if ($effect === PermissionEffect::DENY) {
                return false;
            }

            if ($effect === PermissionEffect::ALLOW) {
                return true;
            }
        }

        // 3. Evaluate active, currently valid role permissions
        $now = now();

        return DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $user->id)
            ->where('roles.status', RoleStatus::ACTIVE->value)
            ->where('permissions.code', $permissionCode)
            ->where(function ($query) use ($now) {
                $query->whereNull('user_roles.valid_from')
                    ->orWhere('user_roles.valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('user_roles.valid_until')
                    ->orWhere('user_roles.valid_until', '>=', $now);
            })
            ->exists();
    }

    /**
     * Get the complete collection of distinct effective permission codes for a user.
     *
     * @return Collection<int, string>
     */
    public function effectivePermissions(User $user): Collection
    {
        if (! $this->isUserActive($user)) {
            return collect();
        }

        // Direct user overrides
        $overrides = DB::table('user_permissions')
            ->join('permissions', 'user_permissions.permission_id', '=', 'permissions.id')
            ->where('user_permissions.user_id', $user->id)
            ->select('permissions.code', 'user_permissions.effect')
            ->get();

        $directDenies = $overrides->where('effect', PermissionEffect::DENY->value)->pluck('code')->all();
        $directAllows = $overrides->where('effect', PermissionEffect::ALLOW->value)->pluck('code')->all();

        // Active, valid role permissions
        $now = now();
        $rolePermissions = DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $user->id)
            ->where('roles.status', RoleStatus::ACTIVE->value)
            ->where(function ($query) use ($now) {
                $query->whereNull('user_roles.valid_from')
                    ->orWhere('user_roles.valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('user_roles.valid_until')
                    ->orWhere('user_roles.valid_until', '>=', $now);
            })
            ->pluck('permissions.code')
            ->all();

        return collect($rolePermissions)
            ->merge($directAllows)
            ->unique()
            ->reject(fn (string $code) => in_array($code, $directDenies, true))
            ->sort()
            ->values();
    }

    /**
     * Check if user has any of the given permissions.
     *
     * @param  list<string>  $permissionCodes
     */
    public function hasAnyPermission(User $user, array $permissionCodes): bool
    {
        foreach ($permissionCodes as $code) {
            if ($this->hasPermission($user, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has all of the given permissions.
     *
     * @param  list<string>  $permissionCodes
     */
    public function hasAllPermissions(User $user, array $permissionCodes): bool
    {
        if (empty($permissionCodes)) {
            return false;
        }

        foreach ($permissionCodes as $code) {
            if (! $this->hasPermission($user, $code)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scoped authorization (AUTH-009): does the user hold the permission for the
     * requested scope context?
     *
     * Precedence mirrors hasPermission() and then applies scope coverage:
     * inactive user -> deny; direct DENY -> deny (global); direct ALLOW -> allow
     * (global, since direct overrides are currently unscoped); otherwise at
     * least one active, valid role assignment granting the permission must cover
     * the requested context. Default: DENY.
     */
    public function can(User $user, string $permissionCode, ScopeContext $context): bool
    {
        return $this->effectiveScope($user, $permissionCode)->covers($context);
    }

    /**
     * Resolve the user's effective scope for a permission (AUTH-009).
     *
     * GLOBAL if a direct ALLOW override exists or any granting role assignment
     * is global; NONE if the user is inactive, a direct DENY exists, or nothing
     * grants it; otherwise SCOPED with the covered site/zone ids. Uses bounded
     * queries (no per-assignment queries).
     */
    public function effectiveScope(User $user, string $permissionCode): EffectiveScope
    {
        if (! $this->isUserActive($user)) {
            return EffectiveScope::none();
        }

        $override = UserPermission::query()
            ->join('permissions', 'user_permissions.permission_id', '=', 'permissions.id')
            ->where('user_permissions.user_id', $user->id)
            ->where('permissions.code', $permissionCode)
            ->value('user_permissions.effect');

        if ($override !== null) {
            $effect = $override instanceof PermissionEffect ? $override : PermissionEffect::tryFrom($override);

            // Direct overrides are unscoped: DENY is a global deny, ALLOW a global allow.
            if ($effect === PermissionEffect::DENY) {
                return EffectiveScope::none();
            }

            if ($effect === PermissionEffect::ALLOW) {
                return EffectiveScope::global();
            }
        }

        $now = now();
        $assignments = DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $user->id)
            ->where('roles.status', RoleStatus::ACTIVE->value)
            ->where('permissions.code', $permissionCode)
            ->where(function ($query) use ($now) {
                $query->whereNull('user_roles.valid_from')
                    ->orWhere('user_roles.valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('user_roles.valid_until')
                    ->orWhere('user_roles.valid_until', '>=', $now);
            })
            ->get(['user_roles.site_id', 'user_roles.zone_id']);

        if ($assignments->isEmpty()) {
            return EffectiveScope::none();
        }

        $siteIds = [];
        $zoneIds = [];

        foreach ($assignments as $assignment) {
            // GLOBAL assignment: both null -> permission applies globally.
            if ($assignment->site_id === null && $assignment->zone_id === null) {
                return EffectiveScope::global();
            }

            if ($assignment->zone_id !== null) {
                $zoneIds[] = (int) $assignment->zone_id; // ZONE-scoped assignment
            } elseif ($assignment->site_id !== null) {
                $siteIds[] = (int) $assignment->site_id; // whole-SITE assignment
            }
        }

        return EffectiveScope::scoped($siteIds, $zoneIds);
    }

    /**
     * Whole-site ids the user can access for a permission. Returns null when the
     * effective scope is GLOBAL (all sites).
     *
     * @return list<int>|null
     */
    public function accessibleSiteIds(User $user, string $permissionCode): ?array
    {
        $scope = $this->effectiveScope($user, $permissionCode);

        return $scope->isGlobal() ? null : $scope->siteIds();
    }

    /**
     * Zone ids the user can access for a permission. Returns null when the
     * effective scope is GLOBAL (all zones).
     *
     * @return list<int>|null
     */
    public function accessibleZoneIds(User $user, string $permissionCode): ?array
    {
        $scope = $this->effectiveScope($user, $permissionCode);

        return $scope->isGlobal() ? null : $scope->zoneIds();
    }

    /**
     * Constrain a query to the rows the user may access for a permission
     * (AUTH-009). Generic — the caller supplies the site/zone columns, keeping
     * this Auth service decoupled from any domain table.
     *
     * GLOBAL -> unchanged; NONE -> no rows; SCOPED -> rows whose site column is
     * in the accessible sites OR whose zone column is in the accessible zones.
     *
     * @template TBuilder of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyScope(
        $query,
        User $user,
        string $permissionCode,
        string $siteColumn = 'site_id',
        string $zoneColumn = 'zone_id',
    ) {
        $scope = $this->effectiveScope($user, $permissionCode);

        if ($scope->isGlobal()) {
            return $query;
        }

        if ($scope->isNone()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($inner) use ($scope, $siteColumn, $zoneColumn) {
            $matched = false;

            if ($scope->siteIds() !== []) {
                $inner->orWhereIn($siteColumn, $scope->siteIds());
                $matched = true;
            }

            if ($scope->zoneIds() !== []) {
                $inner->orWhereIn($zoneColumn, $scope->zoneIds());
                $matched = true;
            }

            if (! $matched) {
                $inner->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Check if user account is currently active and not locked.
     */
    protected function isUserActive(User $user): bool
    {
        if ($user->status !== UserStatus::ACTIVE) {
            return false;
        }

        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            return false;
        }

        return true;
    }
}
