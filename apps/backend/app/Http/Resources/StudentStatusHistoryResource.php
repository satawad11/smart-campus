<?php

namespace App\Http\Resources;

use App\Models\StudentStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe presentation of student academic-status history (STD-008 / STD-016).
 *
 * Exposes old/new status, effective interval, source, reason, and safe actor identity.
 * Internal IDs and security internals are never exposed.
 *
 * @mixin StudentStatusHistory
 */
class StudentStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'old_status' => $this->old_status?->value ?? $this->old_status,
            'new_status' => $this->new_status?->value ?? $this->new_status,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'reason' => $this->reason,
            'source' => $this->source?->value ?? $this->source,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy === null ? null : [
                'uuid' => $this->changedBy->uuid,
                'username' => $this->changedBy->username,
                'display_name' => $this->changedBy->display_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
