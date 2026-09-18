<?php

namespace App\Services\Auth;

use App\Enums\PermissionEffect;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserPermission;
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
