<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Student import upload request (STD-012).
 *
 * Validates uploaded CSV file container, size limits, and strictly prohibits injected fields.
 */
class UploadStudentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced by route permission middleware (student.import)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // 10MB maximum
            ],
            'conflict_mode' => [
                'sometimes',
                'nullable',
                'string',
                'in:CREATE_ONLY,SKIP_EXISTING,UPDATE_EXISTING',
            ],

            // Prohibit spoofed attributes
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
            'status' => ['prohibited'],
            'type' => ['prohibited'],
            'total_rows' => ['prohibited'],
            'valid_rows' => ['prohibited'],
            'invalid_rows' => ['prohibited'],
            'success_rows' => ['prohibited'],
            'failed_rows' => ['prohibited'],
            'created_by' => ['prohibited'],
            'user_id' => ['prohibited'],
            'file_path' => ['prohibited'],
        ];
    }
}
