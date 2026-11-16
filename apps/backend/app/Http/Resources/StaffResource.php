<?php

namespace App\Http\Resources;

use App\Models\StaffProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher/staff representation (Module 09, PPL-003). Public ids are uuids only.
 *
 * @mixin StaffProfile
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'staff_code' => $this->staff_code,
            'position' => $this->position,
            'employment_status' => $this->employment_status?->value ?? $this->employment_status,
            'status_start_date' => $this->status_start_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,

            'person' => $this->whenLoaded('person', fn () => [
                'uuid' => $this->person->uuid,
                'person_code' => $this->person->person_code,
                'first_name' => $this->person->first_name,
                'last_name' => $this->person->last_name,
                'display_name' => $this->person->display_name,
                'gender' => $this->person->gender,
                'person_type' => $this->person->person_type?->value ?? $this->person->person_type,
                'status' => $this->person->status?->value ?? $this->person->status,
            ]),

            'department' => $this->whenLoaded('department', fn () => $this->department === null ? null : [
                'uuid' => $this->department->uuid,
                'code' => $this->department->code,
                'name' => $this->department->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
