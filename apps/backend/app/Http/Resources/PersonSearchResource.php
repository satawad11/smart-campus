<?php

namespace App\Http\Resources;

use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact, privacy-minimised person summary for authorized pickers. */
class PersonSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Person $person */
        $person = $this->resource;
        $student = $person->studentProfile;
        $staff = $person->staffProfile;
        $faceProfile = $person->faceProfile;

        return [
            'uuid' => $person->uuid,
            'person_code' => $person->person_code,
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'display_name' => $person->display_name,
            'name' => $person->displayLabel(),
            'person_type' => $person->person_type?->value ?? $person->person_type,
            'status' => $person->status?->value ?? $person->status,
            'academic_status' => $student?->academic_status?->value ?? $student?->academic_status,
            'employment_status' => $staff?->employment_status?->value ?? $staff?->employment_status,
            'student_code' => $student?->student_code,
            'staff_code' => $staff?->staff_code,
            'face_enrolled' => $faceProfile?->status?->value === 'ACTIVE',
        ];
    }
}
