<?php

namespace App\Http\Resources;

use App\Models\AiNodeCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe AI-node credential metadata (CAM-004). Never the plaintext token or
 * its hash — only lifecycle metadata a UI needs to manage credentials.
 *
 * @mixin AiNodeCredential
 */
class AiNodeCredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status?->value ?? $this->status,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
