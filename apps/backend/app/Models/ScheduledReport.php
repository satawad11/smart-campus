<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\ReportExportFormat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scheduled report configuration (RPT-018, docs/01-database-blueprint.md §10).
 */
class ScheduledReport extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'scheduled_reports';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'report_type',
        'schedule_type',
        'schedule_config',
        'filters',
        'format',
        'enabled',
        'created_by',
        'last_run_at',
        'next_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ReportExportFormat::class,
            'schedule_config' => 'array',
            'filters' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
