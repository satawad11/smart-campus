<?php

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Enums\RoleStatus;
use App\Exceptions\ApiException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Administrative role & permission-matrix management (AUTH-011, AUTH-014).
 *
 * Owns role lifecycle and role_permissions matrix logic with transactional
 * audit logging (AUTH-014).
 */
class RoleManagementService
{
    private const SUPER_ADMIN = 'SUPER_ADMIN';

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Create a custom role.
     *
     * @param  array{code:string, name:string, description?:?string, status?:string}  $data
     */
    public function create(array $data, ?User $actor = null, ?AuditRequestContext $context = null): Role
    {
        return DB::transaction(function () use ($data, $actor, $context) {
            $role = new Role;
            $role->code = $data['code'];
            $role->name = $data['name'];
            $role->description = $data['description'] ?? null;
            $role->system_role = false;
            $role->status = RoleStatus::from($data['status'] ?? RoleStatus::ACTIVE->value);
            $role->save(); // uuid auto-generated

            $this->auditService->record(
                AuditAction::ROLE_CREATED,
                $actor,
                'ROLE',
                $role->uuid,
                newValues: [
                    'code' => $role->code,
                    'name' => $role->name,
                    'status' => $role->status->value,
                ],
                context: $context
            );

            return $role;
        });
    }

    /**
     * Update mutable role fields (name, description, status).
     *
     * @param  array{name?:string, description?:?string, status?:string}  $data
     */
    public function update(Role $role, array $data, ?User $actor = null, ?AuditRequestContext $context = null): Role
    {
        if ($this->isSuperAdmin($role)
            && array_key_exists('status', $data)
            && RoleStatus::from($data['status']) !== RoleStatus::ACTIVE) {
            throw ApiException::conflict(
                'SYSTEM_ROLE_PROTECTED',
                'The SUPER_ADMIN role cannot be disabled.'
            );
        }

        return DB::transaction(function () use ($role, $data, $actor, $context) {
            $oldValues = [];
            $newValues = [];

            if (array_key_exists('name', $data) && $data['name'] !== $role->name) {
                $oldValues['name'] = $role->name;
                $newValues['name'] = $data['name'];
                $role->name = $data['name'];
            }
            if (array_key_exists('description', $data) && $data['description'] !== $role->description) {
                $oldValues['description'] = $role->description;
                $newValues['description'] = $data['description'];
                $role->description = $data['description'];
            }
            if (array_key_exists('status', $data) && RoleStatus::from($data['status']) !== $role->status) {
                $oldValues['status'] = $role->status->value;
                $newValues['status'] = $data['status'];
                $role->status = RoleStatus::from($data['status']);
            }

            if (! empty($newValues)) {
                $role->save();

                $this->auditService->record(
                    AuditAction::ROLE_UPDATED,
                    $actor,
                    'ROLE',
                    $role->uuid,
                    oldValues: $oldValues,
                    newValues: $newValues,
                    context: $context
                );
            }

            return $role;
        });
    }

    /**
     * Replace a role's permission matrix atomically (all-or-nothing).
     *
     * @param  list<string>  $permissionCodes
     */
    public function syncPermissions(Role $role, array $permissionCodes, ?User $actor = null, ?AuditRequestContext $context = null): Role
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

        return DB::transaction(function () use ($role, $codes, $actor, $context) {
            $locked = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();

            $oldCodes = $locked->permissions()->pluck('code')->all();
            $ids = Permission::query()->whereIn('code', $codes)->pluck('id')->all();
            $locked->permissions()->sync($ids);

            $added = array_values(array_diff($codes, $oldCodes));
            $removed = array_values(array_diff($oldCodes, $codes));

            $this->auditService->record(
                AuditAction::ROLE_PERMISSION_MATRIX_UPDATED,
                $actor,
                'ROLE',
                $locked->uuid,
                newValues: [
                    'added' => $added,
                    'removed' => $removed,
                ],
                context: $context
            );

            return $locked->load('permissions');
        });
    }

    private function isSuperAdmin(Role $role): bool
    {
        return $role->code === self::SUPER_ADMIN;
    }
}
