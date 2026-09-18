<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe public representation of a Role (AUTH-011).
 *
 * Exposes the UUID as the external identifier; never internal BIGINT ids,
 * pivot ids, or internal update timestamps. Permission codes are included only
 * on the detail (loaded) response.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'system_role' => (bool) $this->system_role,
            'status' => $this->status?->value ?? $this->status,
            'permissions_count' => $this->whenCounted('permissions'),
            'users_count' => $this->whenCounted('userAssignments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('code')->sort()->values()->all()),
        ];
    }
}
