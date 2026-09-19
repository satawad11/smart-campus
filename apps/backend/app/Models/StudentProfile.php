<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\StudentAcademicStatus;
use Database\Factories\StudentProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Student academic profile (STD-003).
 *
 * Belongs to exactly one Person (canonical 0..1 : 1). It is NOT the master
 * identity (that is Person), NOT a login account (User), and NOT biometric
 * (Module 03 face_profiles reference person_id). academic_status is registry
 * data — never an access decision (Module 05 policy owns that). No academic
 * organization names are duplicated here; only FK ids to the master data.
 *
 * Invariant for later StudentService (STD-006): a StudentProfile's Person should
 * be person_type STUDENT. Not enforced here (schema/model-only task).
 */
class StudentProfile extends Model
{
    /** @use HasFactory<StudentProfileFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'student_profiles';

    /** @var list<string> */
    protected $fillable = [
        'person_id',
        'student_code',
        'department_id',
        'program_id',
        'classroom_id',
        'education_level',
        'year_level',
        'academic_status',
        'status_start_date',
        'status_end_date',
        'admission_year',
        'graduation_year',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_status' => StudentAcademicStatus::class,
            'metadata' => 'array',
            'status_start_date' => 'date',
            'status_end_date' => 'date',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /**
     * Append-only academic-status transition history (STD-004).
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(StudentStatusHistory::class);
    }
}
