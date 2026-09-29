<?php

namespace App\Services\System;

use App\Models\SystemIncident;
use App\Models\User;
use App\Services\Audit\AuditService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class IncidentService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Create/declare a new system incident.
     *
     * @param  array<string, mixed>  $data
     */
    public function createIncident(array $data, ?User $creator = null): SystemIncident
    {
        $year = Carbon::now()->year;
        $count = SystemIncident::whereYear('created_at', $year)->count() + 1;
        $incidentNumber = sprintf('INC-%04d-%04d', $year, $count);

        $incident = SystemIncident::create([
            'incident_number' => $incidentNumber,
            'title' => $data['title'],
            'severity' => $data['severity'] ?? SystemIncident::SEVERITY_SEV2,
            'status' => SystemIncident::STATUS_OPEN,
            'description' => $data['description'],
            'impact_summary' => $data['impact_summary'] ?? null,
            'commander_user_id' => $data['commander_user_id'] ?? $creator?->id,
            'started_at' => $data['started_at'] ?? Carbon::now(),
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->auditService->record(
            action: 'incident.declared',
            actor: $creator,
            subjectType: 'system_incident',
            subjectUuid: $incident->id,
            oldValues: null,
            newValues: ['incident_number' => $incidentNumber, 'severity' => $incident->severity, 'title' => $incident->title],
            metadata: null,
            module: 'incident'
        );

        return $incident;
    }

    /**
     * Update incident status and post-incident investigation details.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateIncident(string $id, array $data, ?User $actor = null): SystemIncident
    {
        $incident = SystemIncident::findOrFail($id);
        $oldValues = $incident->only(['status', 'severity', 'root_cause', 'resolution_summary']);

        $updates = [];
        foreach (['title', 'severity', 'status', 'description', 'impact_summary', 'root_cause', 'resolution_summary', 'action_items'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (isset($data['status'])) {
            if ($data['status'] === SystemIncident::STATUS_MITIGATED && ! $incident->mitigated_at) {
                $updates['mitigated_at'] = Carbon::now();
            } elseif ($data['status'] === SystemIncident::STATUS_RESOLVED && ! $incident->resolved_at) {
                $updates['resolved_at'] = Carbon::now();
            }
        }

        $incident->update($updates);

        $this->auditService->record(
            action: 'incident.updated',
            actor: $actor,
            subjectType: 'system_incident',
            subjectUuid: $incident->id,
            oldValues: $oldValues,
            newValues: $incident->only(array_keys($updates)),
            metadata: null,
            module: 'incident'
        );

        return $incident;
    }

    /**
     * List all incidents.
     *
     * @return Collection<int, SystemIncident>
     */
    public function listIncidents(?string $status = null): Collection
    {
        $query = SystemIncident::query()->with('commander')->latest();

        if ($status) {
            $query->where('status', strtoupper($status));
        }

        return $query->get();
    }
}
