<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\ImportJobStatus;
use App\Enums\ImportJobType;
use Database\Factories\ImportJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Import Job (STD-011, database blueprint §9).
 *
 * Manages batch import jobs for Student and identity records.
 * Identified publicly by UUID.
 * Row counters reflect validation, preview, and execution states.
 */
class ImportJob extends Model
{
    /** @use HasFactory<ImportJobFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'import_jobs';

    /** @var list<string> */
    protected $fillable = [
        'type',
        'file_path',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'success_rows',
        'failed_rows',
        'created_by',
        'started_at',
        'completed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => ImportJobType::STUDENT->value,
        'status' => ImportJobStatus::PENDING->value,
        'total_rows' => 0,
        'valid_rows' => 0,
        'invalid_rows' => 0,
        'success_rows' => 0,
        'failed_rows' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ImportJobType::class,
            'status' => ImportJobStatus::class,
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'success_rows' => 'integer',
            'failed_rows' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The user who initiated this import job.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Row-level errors associated with this import job.
     */
    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class, 'import_job_id');
    }
}
