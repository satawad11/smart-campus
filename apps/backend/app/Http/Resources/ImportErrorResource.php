<?php

namespace App\Http\Resources;

use App\Models\ImportError;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ImportError
 */
class ImportErrorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'row_number' => $this->row_number,
            'field' => $this->field,
            'value' => $this->value !== null ? mb_substr((string) $this->value, 0, 255) : null,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
