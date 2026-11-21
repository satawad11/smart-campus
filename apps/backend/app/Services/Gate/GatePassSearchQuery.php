<?php

namespace App\Services\Gate;

use App\Models\AccessDecision;
use App\Models\Gate;
use App\Models\GatePass;
use App\Models\Person;
use App\Models\Site;
use App\Models\Vehicle;
use App\Support\Vehicles\ThaiPlate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Gate pass history filters (GATE-008, ACR-VEH-01 Phase 5). Read only: passes and their events
 * are never modified here. An unknown uuid or unreadable plate is a real filter that matches
 * nothing, never "no filter".
 */
class GatePassSearchQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function build(array $filters): Builder
    {
        $query = GatePass::query()->with([
            'gate.site',
            'faceEvent',
            'plateEvent',
            'person',
            'vehicle',
            'latestDecision',
        ]);

        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', Carbon::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', Carbon::parse($filters['to']));
        }
        if (! empty($filters['match_result'])) {
            $query->where('match_result', $filters['match_result']);
        }

        if (! empty($filters['gate_uuid'])) {
            $query->where('gate_id', Gate::withTrashed()->where('uuid', $filters['gate_uuid'])->value('id') ?? 0);
        }
        if (! empty($filters['site_uuid'])) {
            $siteId = Site::where('uuid', $filters['site_uuid'])->value('id') ?? 0;
            $query->whereIn('gate_id', Gate::withTrashed()->where('site_id', $siteId)->select('id'));
        }
        if (! empty($filters['person_uuid'])) {
            $query->where('person_id', Person::withTrashed()->where('uuid', $filters['person_uuid'])->value('id') ?? 0);
        }
        if (! empty($filters['vehicle_uuid'])) {
            $query->where('vehicle_id', Vehicle::withTrashed()->where('uuid', $filters['vehicle_uuid'])->value('id') ?? 0);
        }

        // What the plate camera read, normalised like stored plates ("1กข-1234" finds "1กข1234").
        if (! empty($filters['plate'])) {
            $plate = ThaiPlate::tryNormalize((string) $filters['plate']);
            $plate === null
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('plateEvent', fn (Builder $q) => $q->where('metadata->plate->plate_number', $plate));
        }

        // The pass's current decision: its latest decision, so an officer's review counts.
        if (! empty($filters['decision'])) {
            $latestIds = AccessDecision::query()->whereNotNull('gate_pass_id')
                ->selectRaw('MAX(id)')->groupBy('gate_pass_id');
            $query->whereIn('id', AccessDecision::query()
                ->whereIn('id', $latestIds)
                ->where('decision', $filters['decision'])
                ->select('gate_pass_id'));
        }

        return $query;
    }
}
