<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public Audit Log Resource (AUTH-014).
 *
 * Safe representation of security and administrative audit events.
 * Strictly excludes internal database primary/foreign keys.
 */
class AuditLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = null;
        if ($this->relationLoaded('actor') && $this->actor) {
            $actor = [
                'uuid' => $this->actor->uuid,
                'username' => $this->actor->username,
                'name' => $this->actor->name,
            ];
        }

        return [
            'uuid' => $this->uuid,
            'action' => $this->action,
            'module' => $this->module,
            'actor' => $actor,
            'subject_type' => $this->entity_type,
            'subject_uuid' => $this->entity_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
