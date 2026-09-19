<?php

namespace Database\Factories;

use App\Models\ImportError;
use App\Models\ImportJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportError>
 */
class ImportErrorFactory extends Factory
{
    protected $model = ImportError::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_job_id' => ImportJob::factory(),
            'row_number' => 2,
            'field' => 'student_code',
            'value' => 'STD-INVALID',
            'error_code' => 'DUPLICATE_STUDENT_CODE',
            'error_message' => 'The student code STD-INVALID already exists.',
        ];
    }
}
