<?php

namespace App\Services\Event;

use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Camera;
use App\Models\Person;
use App\Models\Site;
use App\Models\StudentProfile;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Shared AccessEvent filter builder, extracted from EventController::index()
 * (EVT-008) so RPT-001's historical search reuses the exact same,
 * already-tested filter semantics rather than a second, potentially
 * diverging implementation — from/to/event_type/person_uuid/site_uuid/
 * zone_uuid/camera_uuid/student_code/academic_status/decision behave
 * identically whichever caller applies them.
 */
class EventSearchQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function build(array $filters): Builder
    {
        $query = AccessEvent::query()->with(['person.studentProfile', 'person.staffProfile', 'site', 'zone', 'camera', 'aiNode', 'latestDecision']);

        if (! empty($filters['from'])) {
            // Parse to Carbon, not the raw string — see EventController's
            // own comment: SQLite stores occurred_at without a 'T'/'Z'
            // separator, so comparing raw ISO-8601 strings as TEXT breaks
            // same-day `>=` (a lexicographic trap PostgreSQL's native
            // timestamp type never exposed).
            $query->where('occurred_at', '>=', Carbon::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', Carbon::parse($filters['to']));
        }
        if (! empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        foreach ([
            'person_uuid' => [Person::class, 'person_id'],
            'site_uuid' => [Site::class, 'site_id'],
            'zone_uuid' => [Zone::class, 'zone_id'],
            'camera_uuid' => [Camera::class, 'camera_id'],
        ] as $param => [$model, $column]) {
            if (! empty($filters[$param])) {
                $id = $model::where('uuid', $filters[$param])->value('id');
                $query->where($column, $id);
            }
        }

        if (! empty($filters['student_code'])) {
            $personId = StudentProfile::where('student_code', $filters['student_code'])->value('person_id');
            $query->where('person_id', $personId ?? 0); // 0 matches nothing — a real but unmatched filter, not "all"
        }

        if (! empty($filters['academic_status'])) {
            $personIds = StudentProfile::where('academic_status', $filters['academic_status'])->pluck('person_id');
            $query->whereIn('person_id', $personIds);
        }

        if (! empty($filters['decision'])) {
            $latestDecisionIds = AccessDecision::selectRaw('MAX(id)')->groupBy('event_id');
            $query->whereHas('decisions', function ($q) use ($filters, $latestDecisionIds) {
                $q->where('decision', $filters['decision'])->whereIn('id', $latestDecisionIds);
            });
        }

        return $query;
    }
}
