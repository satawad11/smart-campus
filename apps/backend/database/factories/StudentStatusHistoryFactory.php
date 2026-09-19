<?php

namespace Database\Factories;

use App\Enums\StatusChangeSource;
use App\Enums\StudentAcademicStatus;
use App\Models\StudentProfile;
use App\Models\StudentStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentStatusHistory>
 *
 * Creates history rows only — it does NOT mutate the related StudentProfile's
 * current academic_status (that synchronization is the status-change service's
 * job, a later task).
 */
class StudentStatusHistoryFactory extends Factory
{
    protected $model = StudentStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'old_status' => null,
            'new_status' => StudentAcademicStatus::ACTIVE,
            'effective_from' => now(),
            'effective_to' => null,
            'reason' => null,
            'source' => StatusChangeSource::MANUAL,
            'changed_by_user_id' => null,
        ];
    }
}
