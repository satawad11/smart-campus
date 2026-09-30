<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Models\Camera;
use App\Models\SystemAlert;
use App\Services\System\AlertLifecycleService;
use App\Support\HealthChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Automatic alert producers for core dependencies, stale cameras and queue
 * backlog (SEC-013 alert table; pilot pre-deployment review). Scheduled every
 * minute. Each condition raises at most one unresolved alert per source.
 *
 * A PostgreSQL outage cannot be recorded as an alert row (the alert store is
 * PostgreSQL); it is logged at CRITICAL and the command exits non-zero, and
 * must be caught by the external uptime probe on /health/full.
 */
class MonitorSystemHealth extends Command
{
    protected $signature = 'system:monitor';

    protected $description = 'Raise system alerts for failed dependencies, stale cameras and queue backlog';

    /** Dependency probe => [alert type, human name]. */
    private const DEPENDENCIES = [
        'redis' => ['REDIS_HEALTH', 'Redis'],
        'storage' => ['STORAGE_HEALTH', 'Object storage'],
        'aiService' => ['AI_SERVICE_HEALTH', 'AI service'],
    ];

    public function handle(HealthChecks $health, AlertLifecycleService $alerts): int
    {
        if (! $health->database()) {
            Log::critical('system:monitor: PostgreSQL unreachable; alert cannot be persisted');
            $this->error('PostgreSQL unreachable.');

            return self::FAILURE;
        }

        $raised = 0;

        foreach (self::DEPENDENCIES as $probe => [$type, $name]) {
            if (! $this->safely(fn () => $health->{$probe}())) {
                $raised += $alerts->triggerOnce(
                    type: $type,
                    severity: SystemAlert::SEVERITY_CRITICAL,
                    title: "{$name} unavailable",
                    message: "{$name} health probe failed.",
                    source: 'dependency:'.$probe,
                ) ? 1 : 0;
            }
        }

        $raised += $this->checkCameras($alerts);
        $raised += $this->checkQueue($alerts);

        $this->info("system:monitor raised {$raised} new alert(s).");

        return self::SUCCESS;
    }

    private function checkCameras(AlertLifecycleService $alerts): int
    {
        $staleAfter = (int) config('camera.health.stale_after_seconds', 120);
        $cutoff = now()->subSeconds($staleAfter);
        $raised = 0;

        $stale = Camera::query()
            ->where('status', DeviceStatus::ACTIVE)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
            ->get();

        foreach ($stale as $camera) {
            $raised += $alerts->triggerOnce(
                type: 'CAMERA_DOWN',
                severity: SystemAlert::SEVERITY_WARNING,
                title: "Camera {$camera->camera_code} offline",
                message: "No health report from camera {$camera->camera_code} for more than {$staleAfter} seconds.",
                source: 'camera:'.$camera->uuid,
                metadata: ['camera_uuid' => $camera->uuid, 'last_seen_at' => $camera->last_seen_at?->toIso8601String()],
            ) ? 1 : 0;
        }

        return $raised;
    }

    private function checkQueue(AlertLifecycleService $alerts): int
    {
        $threshold = (int) config('queue.monitor.backlog_threshold', 1000);
        $size = $this->safely(fn () => Queue::size());

        if (! is_int($size) || $size <= $threshold) {
            return 0;
        }

        return $alerts->triggerOnce(
            type: 'QUEUE_BACKLOG',
            severity: SystemAlert::SEVERITY_WARNING,
            title: 'Queue backlog',
            message: "Default queue holds {$size} jobs (threshold {$threshold}).",
            source: 'queue:default',
            metadata: ['size' => $size, 'threshold' => $threshold],
        ) ? 1 : 0;
    }

    private function safely(callable $probe): mixed
    {
        try {
            return $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
