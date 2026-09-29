<?php

namespace App\Services\System;

use App\Models\SystemAlert;
use App\Models\User;
use App\Services\Audit\AuditService;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection;

class AlertLifecycleService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Trigger or create a new alert.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function triggerAlert(
        string $type,
        string $severity,
        string $title,
        string $message,
        ?string $source = null,
        ?array $metadata = null
    ): SystemAlert {
        $alert = SystemAlert::create([
            'type' => $type,
            'severity' => $severity,
            'source' => $source,
            'title' => $title,
            'message' => $message,
            'status' => SystemAlert::STATUS_OPEN,
            'metadata' => $metadata,
        ]);

        $this->auditService->record(
            action: 'alert.triggered',
            actor: null,
            subjectType: 'system_alert',
            subjectUuid: $alert->id,
            oldValues: null,
            newValues: ['type' => $type, 'severity' => $severity, 'source' => $source],
            metadata: null,
            module: 'system_alert'
        );

        return $alert;
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledgeAlert(string $alertId, User $user): SystemAlert
    {
        $alert = SystemAlert::findOrFail($alertId);

        if ($alert->status !== SystemAlert::STATUS_OPEN) {
            throw new Exception("Alert cannot be acknowledged from status {$alert->status}");
        }

        $oldStatus = $alert->status;

        $alert->update([
            'status' => SystemAlert::STATUS_ACKNOWLEDGED,
            'acknowledged_by_user_id' => $user->id,
            'acknowledged_at' => Carbon::now(),
        ]);

        $this->auditService->record(
            action: 'alert.acknowledged',
            actor: $user,
            subjectType: 'system_alert',
            subjectUuid: $alert->id,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $alert->status, 'acknowledged_by' => $user->id],
            metadata: null,
            module: 'system_alert'
        );

        return $alert;
    }

    /**
     * Resolve an alert.
     */
    public function resolveAlert(string $alertId, User $user, ?string $resolutionNotes = null): SystemAlert
    {
        $alert = SystemAlert::findOrFail($alertId);

        $oldStatus = $alert->status;

        $alert->update([
            'status' => SystemAlert::STATUS_RESOLVED,
            'resolved_by_user_id' => $user->id,
            'resolved_at' => Carbon::now(),
            'resolution_notes' => $resolutionNotes,
        ]);

        $this->auditService->record(
            action: 'alert.resolved',
            actor: $user,
            subjectType: 'system_alert',
            subjectUuid: $alert->id,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $alert->status, 'resolved_by' => $user->id, 'notes' => $resolutionNotes],
            metadata: null,
            module: 'system_alert'
        );

        return $alert;
    }

    /**
     * List alerts with optional filters.
     *
     * @return Collection<int, SystemAlert>
     */
    public function listAlerts(?string $status = null, ?string $severity = null): Collection
    {
        $query = SystemAlert::query()->with(['acknowledgedBy', 'resolvedBy'])->latest();

        if ($status) {
            $query->where('status', strtoupper($status));
        }
        if ($severity) {
            $query->where('severity', strtoupper($severity));
        }

        return $query->get();
    }
}
