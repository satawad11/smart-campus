<?php

namespace App\Http\Resources;

use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe classroom representation (STD-005). Exposes uuid + safe department and
 * (nullable) program references; never internal ids or FK columns.
 *
 * @mixin Classroom
 */
class ClassroomResource extends JsonResource
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
            'academic_year' => $this->academic_year,
            'year_level' => $this->year_level,
            'status' => $this->status?->value ?? $this->status,
            'department' => $this->whenLoaded('department', fn () => [
                'uuid' => $this->department->uuid,
                'code' => $this->department->code,
                'name' => $this->department->name,
            ]),
            'program' => $this->whenLoaded('program', fn () => $this->program === null ? null : [
                'uuid' => $this->program->uuid,
                'code' => $this->program->code,
                'name' => $this->program->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
