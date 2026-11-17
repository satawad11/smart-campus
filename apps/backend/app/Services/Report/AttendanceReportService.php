<?php

namespace App\Services\Report;

use App\Models\AccessEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daily time-in / time-out from recognition events.
 *
 * Nothing is stored separately: for one calendar day (in the given timezone) every
 * event whose person is known and whose current decision is ALLOW counts. The first
 * such event is the time in, the last is the time out. A person seen only once has
 * no time out yet. REVIEW events count only after a reviewer turns them into ALLOW.
 */
class AttendanceReportService
{
    /** Minimum gap before a later sighting counts as a separate time out. */
    private const MIN_OUT_GAP_MINUTES = 1;

    /**
     * @param  Builder<AccessEvent>  $scoped  access_events query already limited to the caller's site scope
     * @param  array{date?: string, tz?: string, person_type?: string, keyword?: string, site_uuid?: string}  $filters
     * @return array{date: string, timezone: string, rows: list<array<string, mixed>>}
     */
    public function daily(Builder $scoped, array $filters): array
    {
        $tz = $filters['tz'] ?? 'Asia/Bangkok';
        $day = isset($filters['date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $filters['date'], $tz)->startOfDay()
            : CarbonImmutable::now($tz)->startOfDay();

        $query = $scoped
            ->whereNotNull('person_id')
            ->whereBetween('occurred_at', [$day->utc(), $day->endOfDay()->utc()])
            ->whereHas('latestDecision', fn ($q) => $q->where('decision', 'ALLOW'))
            ->with(['person.studentProfile', 'person.staffProfile', 'camera', 'site'])
            ->orderBy('occurred_at');

        if (! empty($filters['site_uuid'])) {
            $query->whereHas('site', fn ($q) => $q->where('uuid', $filters['site_uuid']));
        }

        if (! empty($filters['person_type'])) {
            $query->whereHas('person', fn ($q) => $q->where('person_type', $filters['person_type']));
        }

        if (! empty($filters['keyword'])) {
            $keyword = '%'.mb_strtolower($filters['keyword']).'%';
            $query->whereHas('person', function ($q) use ($keyword) {
                $q->whereRaw('LOWER(first_name) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$keyword])
                    ->orWhereHas('studentProfile', fn ($s) => $s->whereRaw('LOWER(student_code) LIKE ?', [$keyword]))
                    ->orWhereHas('staffProfile', fn ($s) => $s->whereRaw('LOWER(staff_code) LIKE ?', [$keyword]));
            });
        }

        $rows = $query->get()
            ->groupBy('person_id')
            ->map(fn (Collection $events) => $this->row($events, $tz))
            ->sortBy('time_in')
            ->values()
            ->all();

        return ['date' => $day->toDateString(), 'timezone' => $tz, 'rows' => $rows];
    }

    /**
     * @param  Collection<int, AccessEvent>  $events  one person's events, oldest first
     * @return array<string, mixed>
     */
    private function row(Collection $events, string $tz): array
    {
        $first = $events->first();
        $last = $events->last();
        $person = $first->person;
        $hasOut = $events->count() > 1
            && $first->occurred_at->diffInMinutes($last->occurred_at) >= self::MIN_OUT_GAP_MINUTES;

        return [
            'person' => [
                'uuid' => $person->uuid,
                'name' => $person->displayLabel(),
                'person_type' => $person->person_type?->value ?? $person->person_type,
                'code' => $person->studentProfile?->student_code ?? $person->staffProfile?->staff_code ?? $person->person_code,
                'status' => $person->studentProfile?->academic_status?->value
                    ?? $person->studentProfile?->academic_status
                    ?? $person->staffProfile?->employment_status?->value
                    ?? $person->staffProfile?->employment_status
                    ?? $person->status?->value
                    ?? $person->status,
                'academic_status' => $person->studentProfile?->academic_status?->value ?? $person->studentProfile?->academic_status,
                'employment_status' => $person->staffProfile?->employment_status?->value ?? $person->staffProfile?->employment_status,
            ],
            'time_in' => $first->occurred_at->setTimezone($tz)->toIso8601String(),
            'time_in_camera' => $first->camera?->name,
            'time_out' => $hasOut ? $last->occurred_at->setTimezone($tz)->toIso8601String() : null,
            'time_out_camera' => $hasOut ? $last->camera?->name : null,
            'sightings' => $events->count(),
            'site' => $first->site?->name,
        ];
    }
}
