<?php

namespace App\Services\Report;

use App\Models\EventDailySummary;
use App\Models\EventHourlySummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RPT-004 — recomputes event_hourly_summaries/event_daily_summaries
 * (RPT-003) from raw AccessEvent/AccessDecision for a given bucket.
 * Raw events remain the source of truth (docs/10's own words); every call
 * here is a pure re-derivation, safe to run repeatedly over the same
 * bucket (idempotent upsert, never a blind insert — see RPT-003's
 * migration comment on why the DB unique constraint alone isn't enough
 * once a dimension is NULL).
 *
 * Deliberately avoids SQL date-truncation functions (e.g. PostgreSQL's
 * date_trunc, which SQLite doesn't have): the caller passes an
 * already-truncated bucket boundary, and this class filters
 * occurred_at with a plain range comparison — the same portable pattern
 * EventSearchQuery already established for from/to filtering.
 */
class EventSummaryRefreshService
{
    public function refreshHour(Carbon $hourStart): int
    {
        $hourEnd = $hourStart->copy()->addHour();
        $rows = $this->aggregate($hourStart, $hourEnd);

        foreach ($rows as $row) {
            $this->upsert(EventHourlySummary::class, [
                'site_id' => $row->site_id,
                'zone_id' => $row->zone_id,
                'camera_id' => $row->camera_id,
                'department_id' => $row->department_id,
                'hour_bucket' => $hourStart,
                'event_type' => $row->event_type,
                'decision' => $row->decision,
            ], (int) $row->event_count, (int) $row->unique_person_count);
        }

        return count($rows);
    }

    public function refreshDay(Carbon $dayStart): int
    {
        $dayEnd = $dayStart->copy()->addDay();
        $rows = $this->aggregate($dayStart, $dayEnd);

        foreach ($rows as $row) {
            $this->upsert(EventDailySummary::class, [
                'site_id' => $row->site_id,
                'zone_id' => $row->zone_id,
                'camera_id' => $row->camera_id,
                'department_id' => $row->department_id,
                'summary_date' => $dayStart->toDateString(),
                'event_type' => $row->event_type,
                'decision' => $row->decision,
            ], (int) $row->event_count, (int) $row->unique_person_count);
        }

        return count($rows);
    }

    /**
     * Groups raw events (joined to their LATEST decision — never the
     * original, same rule EventSearchQuery's decision filter already
     * follows — and, via person -> student_profile, their department) by
     * every summary dimension, for the half-open window [start, end).
     *
     * @return array<int, object{site_id:int, zone_id:?int, camera_id:?int, department_id:?int, event_type:string, decision:string, event_count:int, unique_person_count:int}>
     */
    private function aggregate(Carbon $start, Carbon $end): array
    {
        $latestDecisionIds = DB::table('access_decisions')
            ->select('event_id', DB::raw('MAX(id) as latest_id'))
            ->groupBy('event_id');

        return DB::table('access_events')
            ->joinSub($latestDecisionIds, 'latest', fn ($join) => $join->on('latest.event_id', '=', 'access_events.id'))
            ->join('access_decisions', 'access_decisions.id', '=', 'latest.latest_id')
            ->leftJoin('persons', 'persons.id', '=', 'access_events.person_id')
            ->leftJoin('student_profiles', 'student_profiles.person_id', '=', 'persons.id')
            ->where('access_events.occurred_at', '>=', $start)
            ->where('access_events.occurred_at', '<', $end)
            ->select([
                'access_events.site_id',
                'access_events.zone_id',
                'access_events.camera_id',
                'student_profiles.department_id',
                'access_events.event_type',
                'access_decisions.decision',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('COUNT(DISTINCT access_events.person_id) as unique_person_count'),
            ])
            ->groupBy([
                'access_events.site_id',
                'access_events.zone_id',
                'access_events.camera_id',
                'student_profiles.department_id',
                'access_events.event_type',
                'access_decisions.decision',
            ])
            ->get()
            ->all();
    }

    /**
     * @param  class-string<EventHourlySummary|EventDailySummary>  $modelClass
     * @param  array<string, mixed>  $dimensions
     */
    private function upsert(string $modelClass, array $dimensions, int $eventCount, int $uniquePersonCount): void
    {
        $query = $modelClass::query();
        foreach ($dimensions as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);
            } elseif ($column === 'summary_date') {
                // whereDate(), not where(): a `date`-cast column serializes
                // through Eloquent as a full 'Y-m-d 00:00:00' string, and
                // SQLite (unlike PostgreSQL's native DATE type, which
                // discards the time part on write) stores exactly that as
                // TEXT — a plain where() against a bare 'Y-m-d' value then
                // never matches on SQLite, silently re-inserting a
                // duplicate row every refresh instead of updating.
                $query->whereDate($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        $existing = $query->first();

        if ($existing !== null) {
            $existing->update([
                'event_count' => $eventCount,
                'unique_person_count' => $uniquePersonCount,
                'refreshed_at' => now(),
            ]);

            return;
        }

        $modelClass::create([
            ...$dimensions,
            'event_count' => $eventCount,
            'unique_person_count' => $uniquePersonCount,
            'refreshed_at' => now(),
        ]);
    }
}
