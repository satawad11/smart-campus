<?php

namespace App\Models;

use Database\Factories\ImportErrorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Import Error (STD-011, database blueprint §9).
 *
 * Immutable record of a validation or processing failure for a specific row
 * in an import job.
 */
class ImportError extends Model
{
    /** @use HasFactory<ImportErrorFactory> */
    use HasFactory;

    protected $table = 'import_errors';

    // Immutable created-only record (no updated_at column).
    public $timestamps = true;

    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'import_job_id',
        'row_number',
        'field',
        'value',
        'error_code',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
        ];
    }

    /**
     * The parent import job.
     */
    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }

    /**
     * Alias for importJob.
     */
    public function job(): BelongsTo
    {
        return $this->importJob();
    }
}
