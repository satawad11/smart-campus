<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public Login History Resource (AUTH-013).
 *
 * Safe representation of authentication attempt records.
 * Excludes internal surrogate database IDs (id, user_id),
 * credentials, and session tokens.
 */
class LoginHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->success ? 'SUCCESS' : ($this->failure_reason ?? 'FAILED');

        $user = null;
        if ($this->relationLoaded('user') && $this->user) {
            $user = [
                'uuid' => $this->user->uuid,
                'username' => $this->user->username,
                'name' => $this->user->name,
            ];
        }

        return [
            'username' => $this->username,
            'user' => $user,
            'status' => $status,
            'success' => (bool) $this->success,
            'failure_reason' => $this->failure_reason,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
