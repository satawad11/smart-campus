<?php

namespace App\Http\Resources;

use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe program representation (STD-005). Exposes uuid + a safe department
 * reference (uuid/code/name); never the internal id or department_id.
 *
 * @mixin Program
 */
class ProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'education_level' => $this->education_level,
            'status' => $this->status?->value ?? $this->status,
            'department' => $this->whenLoaded('department', fn () => [
                'uuid' => $this->department->uuid,
                'code' => $this->department->code,
                'name' => $this->department->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
