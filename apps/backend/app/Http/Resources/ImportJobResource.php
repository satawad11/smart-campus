<?php

namespace App\Http\Resources;

use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ImportJob
 */
class ImportJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : (string) $this->type,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'conflict_mode' => $this->conflict_mode instanceof \BackedEnum ? $this->conflict_mode->value : (string) ($this->conflict_mode ?? 'CREATE_ONLY'),
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'invalid_rows' => $this->invalid_rows,
            'success_rows' => $this->success_rows,
            'failed_rows' => $this->failed_rows,
            'skipped_rows' => $this->skipped_rows,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'creator' => $this->whenLoaded('creator', function () {
                return $this->creator ? [
                    'uuid' => $this->creator->uuid,
                    'username' => $this->creator->username,
                ] : null;
            }, function () {
                return $this->creator ? [
                    'uuid' => $this->creator->uuid,
                    'username' => $this->creator->username,
                ] : null;
            }),
        ];
    }
}
