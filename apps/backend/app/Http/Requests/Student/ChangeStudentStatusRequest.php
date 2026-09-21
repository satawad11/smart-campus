<?php

namespace App\Http\Requests\Student;

use App\Enums\StudentAcademicStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request to change a student's academic status (STD-008 / STD-016).
 *
 * Accepts the target academic status and an optional reason.
 * The backend determines the actor (authenticated user), the source (API),
 * and the effective timestamps automatically.
 */
class ChangeStudentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission checked by route middleware (student.status.change)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required_without:academic_status',
                'string',
                Rule::in(array_column(StudentAcademicStatus::cases(), 'value')),
            ],
            'academic_status' => [
                'nullable',
                'string',
                Rule::in(array_column(StudentAcademicStatus::cases(), 'value')),
            ],
            'reason' => ['nullable', 'string', 'max:1000'],

            // System-controlled fields are strictly prohibited
            'old_status' => ['prohibited'],
            'source' => ['prohibited'],
            'effective_from' => ['prohibited'],
            'effective_to' => ['prohibited'],
            'status_start_date' => ['prohibited'],
            'status_end_date' => ['prohibited'],
            'changed_by_user_id' => ['prohibited'],
            'student_profile_id' => ['prohibited'],
            'person_id' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
        ];
    }
}
