<?php

namespace App\Http\Resources;

use App\Models\AccessDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe AccessDecision representation (EVT-008).
 *
 * @mixin AccessDecision
 */
class AccessDecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'decision' => $this->decision?->value ?? $this->decision,
            'reason_code' => $this->reason_code,
            'reason_text' => $this->reason_text,
            'decision_source' => $this->decision_source?->value ?? $this->decision_source,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy === null ? null : [
                'uuid' => $this->decidedBy->uuid,
                'username' => $this->decidedBy->username,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
