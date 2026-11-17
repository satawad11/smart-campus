<?php

namespace App\Services\System;

use App\Models\AccessEvent;
use App\Models\AiNodeHealthLog;
use App\Models\AuditLog;
use App\Models\CameraHealthLog;
use App\Models\ReportExport;
use App\Services\Audit\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RetentionCleanupService
{
    public function __construct(
        protected SystemSettingService $settingsService,
        protected AuditService $auditService
    ) {}

    /**
     * Run all retention cleanup policies according to configured settings.
     *
     * @return array<string, int> count of records/files pruned per category
     */
    public function runAllRetentionPolicies(bool $dryRun = false): array
    {
        $results = [
            'snapshots' => $this->cleanupSnapshots($dryRun),
            'camera_health' => $this->cleanupCameraHealthLogs($dryRun),
            'ai_node_health' => $this->cleanupAiNodeHealthLogs($dryRun),
            'report_exports' => $this->cleanupReportExports($dryRun),
            'login_logs' => $this->cleanupLoginLogs($dryRun),
            'plate_reads' => $this->redactPlateReads($dryRun),
            'events' => $this->cleanupEvents($dryRun),
        ];

        if (! $dryRun) {
            $this->auditService->record(
                action: 'retention.cleanup_executed',
                actor: null,
                subjectType: 'system_retention',
                subjectUuid: 'retention_all',
                oldValues: null,
                newValues: $results,
                metadata: null,
                module: 'retention'
            );
        }

        return $results;
    }

    /**
     * Clean up snapshots older than retention.snapshots_days.
     */
    public function cleanupSnapshots(bool $dryRun = false): int
    {
        $days = (int) $this->settingsService->get('retention.snapshots_days', 30);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $events = AccessEvent::query()
            ->whereNotNull('snapshot_path')
            ->where('occurred_at', '<', $cutoff)
            ->get(['id', 'snapshot_path']);

        $count = 0;
        foreach ($events as $event) {
            $path = $event->snapshot_path;
            if (! $dryRun) {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
                $event->update(['snapshot_path' => null]);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Clean up camera health telemetry older than retention.camera_health_days.
     */
    public function cleanupCameraHealthLogs(bool $dryRun = false): int
    {
        $days = (int) $this->settingsService->get('retention.camera_health_days', 14);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $query = CameraHealthLog::query()->where('recorded_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }

    /**
     * Clean up AI node health logs older than retention.ai_node_health_days.
     */
    public function cleanupAiNodeHealthLogs(bool $dryRun = false): int
    {
        $days = (int) $this->settingsService->get('retention.ai_node_health_days', 14);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $query = AiNodeHealthLog::query()->where('recorded_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }

    /**
     * Clean up report export records/files older than retention.report_exports_days.
     */
    public function cleanupReportExports(bool $dryRun = false): int
    {
        $days = (int) $this->settingsService->get('retention.report_exports_days', 7);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $exports = ReportExport::query()
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;
        foreach ($exports as $export) {
            if (! $dryRun) {
                if ($export->file_path && Storage::disk('local')->exists($export->file_path)) {
                    Storage::disk('local')->delete($export->file_path);
                }
                $export->delete();
            }
            $count++;
        }

        return $count;
    }

    /**
     * Clean up login audit logs older than retention.login_logs_days.
     */
    public function cleanupLoginLogs(bool $dryRun = false): int
    {
        $days = (int) $this->settingsService->get('retention.login_logs_days', 365);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $query = AuditLog::query()
            ->where('action', 'LIKE', 'auth.login%')
            ->where('created_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }

    /**
     * VEH-013 (ACR-VEH-01): wipe the plate text, province and vehicle link from plate events
     * older than retention.plate_reads_days. The event row (type, time, camera, OCR score) stays
     * so counts remain correct until retention.events_days deletes it. Idempotent.
     */
    public function redactPlateReads(bool $dryRun = false): int
    {
        $days = (int) $this->settingsService->get('retention.plate_reads_days', 30);
        if ($days <= 0) {
            return 0;
        }

        $query = AccessEvent::query()
            ->whereIn('event_type', ['PLATE_RECOGNIZED', 'PLATE_UNKNOWN', 'PLATE_LOW_CONFIDENCE'])
            ->where('occurred_at', '<', Carbon::now()->subDays($days))
            ->whereNull('metadata->plate->redacted_at');

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;
        $query->chunkById(500, function ($events) use (&$count) {
            // GATE-003: a gate pass keeps the vehicle link it got from this plate read; it goes
            // with the plate text. Query builder on purpose: GatePass is immutable for everyone
            // else.
            DB::table('gate_passes')->whereIn('plate_event_id', $events->pluck('id'))->update(['vehicle_id' => null]);

            foreach ($events as $event) {
                $metadata = $event->metadata ?? [];
                $plate = $metadata['plate'] ?? [];
                foreach (['raw_text', 'raw_province', 'plate_number', 'plate_display', 'plate_province', 'vehicle_uuid'] as $field) {
                    $plate[$field] = null;
                }
                $plate['redacted_at'] = now()->toIso8601String();
                $metadata['plate'] = $plate;
                $event->forceFill(['metadata' => $metadata])->save();
                $count++;
            }
        });

        return $count;
    }

    /**
     * Clean up raw access events and decisions older than retention.events_days.
     */
    public function cleanupEvents(bool $dryRun = false): int
    {
        $days = (int) $this->settingsService->get('retention.events_days', 90);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $query = AccessEvent::query()->where('occurred_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        // Delete decisions first to respect FK if not cascade
        $eventIds = (clone $query)->pluck('id');
        if ($eventIds->isNotEmpty()) {
            DB::table('access_decisions')->whereIn('event_id', $eventIds)->delete();
            // GATE-003: a gate pass only links events, so it goes with them (FK is RESTRICT).
            DB::table('gate_passes')
                ->whereIn('face_event_id', $eventIds)
                ->orWhereIn('plate_event_id', $eventIds)
                ->delete();
        }

        return $query->delete();
    }
}
