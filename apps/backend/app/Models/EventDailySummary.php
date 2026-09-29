<?php

namespace App\Models;

use App\Enums\AccessDecisionOutcome;
use Database\Factories\EventDailySummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pre-aggregated daily event counts (RPT-003). Same purpose and caveats as
 * EventHourlySummary, at day granularity for daily/weekly/monthly/yearly
 * reports (RPT-007..RPT-010). Refreshed by RPT-004's job, never written to
 * directly by request-time code.
 *
 * @use HasFactory<EventDailySummaryFactory>
 */
class EventDailySummary extends Model
{
    use HasFactory;

    protected $table = 'event_daily_summaries';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'site_id',
        'zone_id',
        'camera_id',
        'department_id',
        'summary_date',
        'event_type',
        'decision',
        'event_count',
        'unique_person_count',
        'refreshed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => AccessDecisionOutcome::class,
            'summary_date' => 'date',
            'refreshed_at' => 'datetime',
            'event_count' => 'integer',
            'unique_person_count' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
