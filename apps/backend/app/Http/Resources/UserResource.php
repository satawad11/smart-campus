<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe public representation of a User (AUTH-005).
 *
 * Excludes internal BIGINT ids, password hashes, remember tokens, MFA secrets,
 * and internal security metadata (failed_login_count, locked_until).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status?->value ?? $this->status,
            'mfa_enabled' => (bool) $this->mfa_enabled,
            'must_change_password' => (bool) $this->must_change_password,
            'roles' => $this->roles->map(function ($role) {
                return [
                    'uuid' => $role->uuid,
                    'code' => $role->code,
                    'name' => $role->name,
                ];
            })->values()->all(),
        ];
    }
}
