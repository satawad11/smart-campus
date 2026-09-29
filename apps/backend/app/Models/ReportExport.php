<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Report export job record (RPT-013, docs/01-database-blueprint.md §10).
 */
class ReportExport extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'report_exports';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'report_type',
        'format',
        'filters',
        'status',
        'file_path',
        'file_size',
        'error_message',
        'expires_at',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ReportExportFormat::class,
            'status' => ReportExportStatus::class,
            'filters' => 'array',
            'file_size' => 'integer',
            'expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->status === ReportExportStatus::EXPIRED
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }
}
