<?php

namespace App\Http\Resources;

use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe public representation of an application session (AUTH-012).
 *
 * Excludes internal database id, user_id, and framework session_id.
 * is_current is computed server-side from the active request session.
 *
 * @mixin UserSession
 */
class SessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

        return [
            'uuid' => $this->uuid,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'device_name' => $this->device_name,
            'login_at' => $this->login_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'logout_at' => $this->logout_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_current' => $currentSessionId !== null && $this->session_id === $currentSessionId,
        ];
    }
}
