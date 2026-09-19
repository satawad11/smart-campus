<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\StatusChangeSource;
use App\Enums\StudentAcademicStatus;
use Database\Factories\StudentStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Student academic-status history (STD-004).
 *
 * Append-only historical fact of an academic-status transition. old/new status
 * are StudentAcademicStatus (registry data) — never PersonStatus and never an
 * access decision (Module 05 owns ALLOW/REVIEW/DENY). This is domain history,
 * distinct from the AUTH-014 security audit log. Rows are immutable: created_at
 * only, no updated_at; no mutation helpers are provided.
 */
class StudentStatusHistory extends Model
{
    /** @use HasFactory<StudentStatusHistoryFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'student_status_histories';

    // Immutable created-only record (no updated_at column).
    public $timestamps = true;

    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'student_profile_id',
        'old_status',
        'new_status',
        'effective_from',
        'effective_to',
        'reason',
        'source',
        'changed_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_status' => StudentAcademicStatus::class,
            'new_status' => StudentAcademicStatus::class,
            'source' => StatusChangeSource::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
