<?php

namespace Database\Factories;

use App\Enums\ImportConflictMode;
use App\Enums\ImportJobStatus;
use App\Enums\ImportJobType;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportJob>
 */
class ImportJobFactory extends Factory
{
    protected $model = ImportJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ImportJobType::STUDENT,
            'file_path' => 'imports/'.fake()->uuid().'.xlsx',
            'status' => ImportJobStatus::PENDING,
            'conflict_mode' => ImportConflictMode::CREATE_ONLY,
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'skipped_rows' => 0,
            'created_by' => User::factory(),
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
