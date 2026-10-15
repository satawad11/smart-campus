<?php

namespace App\Services\Report;

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
 * A refresh only inserts or updates rows; it never deletes a summary row,
 * so summaries outlive raw events removed by retention.
 *
 * Deliberately avoids SQL date-truncation functions (e.g. PostgreSQL's
 * date_trunc, which SQLite doesn't have): the caller passes an
 * already-truncated bucket boundary, and this class filters
 * occurred_at with a plain range comparison — the same portable pattern
 * EventSearchQuery already established for from/to filtering.
 *
 * V1 audit A-22 (1M raw events): the latest-decision lookup is limited to
 * the bucket's events, and each bucket is written with one read of its
 * existing rows, a bulk insert and per-row updates only where counts
 * changed, inside one transaction, instead of a SELECT plus a write for
 * every summary row.
 */
class EventSummaryRefreshService
{
    private const DIMENSIONS = ['site_id', 'zone_id', 'camera_id', 'department_id', 'event_type', 'decision'];

    public function refreshHour(Carbon $hourStart): int
    {
        $rows = $this->aggregate($hourStart, $hourStart->copy()->addHour());

        return $this->store('event_hourly_summaries', 'hour_bucket', $hourStart->copy(), $rows, dateOnly: false);
    }

    public function refreshDay(Carbon $dayStart): int
    {
        $rows = $this->aggregate($dayStart, $dayStart->copy()->addDay());

        return $this->store('event_daily_summaries', 'summary_date', $dayStart->copy()->startOfDay(), $rows, dateOnly: true);
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
        $eventsInWindow = fn ($q) => $q->select('id')->from('access_events')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end);

        $latestDecisionIds = DB::table('access_decisions')
            ->select('event_id', DB::raw('MAX(id) as latest_id'))
            ->whereIn('event_id', $eventsInWindow)
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
     * Upserts one bucket. Existing rows are matched on every dimension in
     * PHP (NULL-safe), so the NULL-distinct unique constraint is never relied
     * on. The bucket value is bound as a Carbon instance, which the query
     * builder formats exactly as the models' date/datetime casts do.
     *
     * @param  array<int, object>  $rows
     */
    private function store(string $table, string $bucketColumn, Carbon $bucket, array $rows, bool $dateOnly): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::transaction(function () use ($table, $bucketColumn, $bucket, $rows, $dateOnly) {
            $now = now();
            $bucketQuery = fn () => $dateOnly
                // whereDate(), not where(): SQLite stores a `date`-cast column
                // as 'Y-m-d 00:00:00' TEXT, which a bare 'Y-m-d' never equals.
                ? DB::table($table)->whereDate($bucketColumn, $bucket->toDateString())
                : DB::table($table)->where($bucketColumn, $bucket);

            $existing = [];
            foreach ($bucketQuery()->get(['id', 'event_count', 'unique_person_count', ...self::DIMENSIONS]) as $row) {
                $existing[$this->key($row)] = $row;
            }

            $inserts = [];
            $unchangedIds = [];
            foreach ($rows as $row) {
                $eventCount = (int) $row->event_count;
                $uniqueCount = (int) $row->unique_person_count;
                $current = $existing[$this->key($row)] ?? null;

                if ($current === null) {
                    $inserts[] = [
                        'site_id' => $row->site_id,
                        'zone_id' => $row->zone_id,
                        'camera_id' => $row->camera_id,
                        'department_id' => $row->department_id,
                        $bucketColumn => $bucket,
                        'event_type' => $row->event_type,
                        'decision' => $row->decision,
                        'event_count' => $eventCount,
                        'unique_person_count' => $uniqueCount,
                        'refreshed_at' => $now,
                    ];
                } elseif ((int) $current->event_count !== $eventCount || (int) $current->unique_person_count !== $uniqueCount) {
                    DB::table($table)->where('id', $current->id)->update([
                        'event_count' => $eventCount,
                        'unique_person_count' => $uniqueCount,
                        'refreshed_at' => $now,
                    ]);
                } else {
                    $unchangedIds[] = $current->id;
                }
            }

            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
            foreach (array_chunk($unchangedIds, 1000) as $chunk) {
                DB::table($table)->whereIn('id', $chunk)->update(['refreshed_at' => $now]);
            }
        });

        return count($rows);
    }

    private function key(object $row): string
    {
        $parts = [];
        foreach (self::DIMENSIONS as $column) {
            $value = $row->{$column};
            $parts[] = $value === null ? "\0" : (string) $value;
        }

        return implode('|', $parts);
    }
}
