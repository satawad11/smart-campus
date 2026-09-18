<?php

namespace App\Services\Auth;

use App\Enums\RoleStatus;
use App\Exceptions\ApiException;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Administrative role & permission-matrix management (AUTH-011).
 *
 * Owns role lifecycle and role_permissions matrix logic so controllers stay
 * thin. Authorization is enforced by permission middleware (role.manage), never
 * by role name. System-role protections here are configuration invariants, not
 * authorization bypasses. Emits no audit records (AUTH-014) but exposes discrete
 * methods that later audit hooks can capture: ROLE_CREATED / ROLE_UPDATED /
 * PERMISSION_UPDATED.
 */
class RoleManagementService
{
    private const SUPER_ADMIN = 'SUPER_ADMIN';

    /**
     * Create a custom role. system_role is always false; UUID is server-side.
     *
     * @param  array{code:string, name:string, description?:?string, status?:string}  $data
     */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = new Role;
            $role->code = $data['code'];
            $role->name = $data['name'];
            $role->description = $data['description'] ?? null;
            $role->system_role = false;
            $role->status = RoleStatus::from($data['status'] ?? RoleStatus::ACTIVE->value);
            $role->save(); // uuid auto-generated

            return $role;
        });
    }

    /**
     * Update mutable role fields (name, description, status). Code and
     * system_role are immutable (rejected at the request layer). SUPER_ADMIN
     * cannot be disabled.
     *
     * @param  array{name?:string, description?:?string, status?:string}  $data
     */
    public function update(Role $role, array $data): Role
    {
        if ($this->isSuperAdmin($role)
            && array_key_exists('status', $data)
            && RoleStatus::from($data['status']) !== RoleStatus::ACTIVE) {
            throw ApiException::conflict(
                'SYSTEM_ROLE_PROTECTED',
                'The SUPER_ADMIN role cannot be disabled.'
            );
        }

        return DB::transaction(function () use ($role, $data) {
            if (array_key_exists('name', $data)) {
                $role->name = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $role->description = $data['description'];
            }
            if (array_key_exists('status', $data)) {
                $role->status = RoleStatus::from($data['status']);
            }
            $role->save();

            return $role;
        });
    }

    /**
     * Replace a role's permission matrix atomically (all-or-nothing).
     *
     * Codes are validated to exist at the request layer. SUPER_ADMIN must retain
     * the full canonical permission catalog. Concurrency: the role row is locked
     * for the duration so two concurrent replacements are serialized
     * (last-writer-wins, deterministic and atomic).
     *
     * @param  list<string>  $permissionCodes
     */
    public function syncPermissions(Role $role, array $permissionCodes): Role
    {
        $codes = array_values(array_unique($permissionCodes));

        // SUPER_ADMIN permission-integrity invariant: cannot drop any approved
        // permission from the canonical catalog.
        if ($this->isSuperAdmin($role)) {
            $catalog = Permission::query()->pluck('code')->all();
            $missing = array_diff($catalog, $codes);
            if ($missing !== []) {
                throw ApiException::conflict(
                    'SUPER_ADMIN_PERMISSIONS_REQUIRED',
                    'The SUPER_ADMIN role must retain all approved permissions.'
                );
            }
        }

        return DB::transaction(function () use ($role, $codes) {
            $locked = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();

            $ids = Permission::query()->whereIn('code', $codes)->pluck('id')->all();
            $locked->permissions()->sync($ids);

            return $locked->load('permissions');
        });
    }

    private function isSuperAdmin(Role $role): bool
    {
        return $role->code === self::SUPER_ADMIN;
    }
}
