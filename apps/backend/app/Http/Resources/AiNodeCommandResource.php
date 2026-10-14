<?php

namespace App\Http\Resources;

use App\Models\AiNodeCommand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiNodeCommand
 */
class AiNodeCommandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'command' => $this->command->value,
            'status' => $this->status,
            'reason' => $this->reason,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy === null ? null : [
                'uuid' => $this->requestedBy->uuid,
                'username' => $this->requestedBy->username,
            ]),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'result_message' => $this->result_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
