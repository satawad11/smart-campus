<?php

namespace App\Models;

use App\Enums\AccessDecisionOutcome;
use Database\Factories\EventHourlySummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pre-aggregated hourly event counts (RPT-003). A derived cache over
 * AccessEvent/AccessDecision, never a source of truth — see the migration
 * for the full rationale and the NULL-dimension uniqueness caveat.
 * Refreshed by RPT-004's job, never written to directly by request-time
 * code.
 *
 * @use HasFactory<EventHourlySummaryFactory>
 */
class EventHourlySummary extends Model
{
    use HasFactory;

    protected $table = 'event_hourly_summaries';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'site_id',
        'zone_id',
        'camera_id',
        'department_id',
        'hour_bucket',
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
            'hour_bucket' => 'datetime',
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
