<?php

namespace App\Http\Requests\Staff;

use App\Enums\StaffEmploymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStaffStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware (staff.status.change)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(StaffEmploymentStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'source' => ['prohibited'],
            'changed_by_user_id' => ['prohibited'],
            'effective_from' => ['prohibited'],
        ];
    }
}
