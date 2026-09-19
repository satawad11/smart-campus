<?php

namespace App\Http\Resources;

use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe composed Student representation (STD-007).
 *
 * Public identity is the StudentProfile uuid. Exposes safe Person identity and
 * safe academic master references via whenLoaded (eager-loaded by the
 * controller to avoid N+1). Never exposes internal ids/FKs, the linked User
 * account, status-history internals, or any access decision (Module 05 owns
 * ALLOW/REVIEW/DENY). JSONB metadata is intentionally omitted — it is not a
 * canonically public field.
 *
 * @mixin StudentProfile
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'student_code' => $this->student_code,
            'academic_status' => $this->academic_status?->value ?? $this->academic_status,
            'education_level' => $this->education_level,
            'year_level' => $this->year_level,
            'status_start_date' => $this->status_start_date?->toDateString(),
            'status_end_date' => $this->status_end_date?->toDateString(),
            'admission_year' => $this->admission_year,
            'graduation_year' => $this->graduation_year,

            'person' => $this->whenLoaded('person', fn () => [
                'uuid' => $this->person->uuid,
                'person_code' => $this->person->person_code,
                'first_name' => $this->person->first_name,
                'last_name' => $this->person->last_name,
                'display_name' => $this->person->display_name,
                'person_type' => $this->person->person_type?->value ?? $this->person->person_type,
                'status' => $this->person->status?->value ?? $this->person->status,
            ]),

            'department' => $this->whenLoaded('department', fn () => [
                'uuid' => $this->department->uuid,
                'code' => $this->department->code,
                'name' => $this->department->name,
            ]),

            'program' => $this->whenLoaded('program', fn () => $this->program === null ? null : [
                'uuid' => $this->program->uuid,
                'code' => $this->program->code,
                'name' => $this->program->name,
                'education_level' => $this->program->education_level,
            ]),

            'classroom' => $this->whenLoaded('classroom', fn () => $this->classroom === null ? null : [
                'uuid' => $this->classroom->uuid,
                'code' => $this->classroom->code,
                'name' => $this->classroom->name,
                'academic_year' => $this->classroom->academic_year,
                'year_level' => $this->classroom->year_level,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
