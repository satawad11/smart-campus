<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
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
        $raised += $this->checkNodeModels($alerts);
        $raised += $this->checkNodeDisks($alerts);
        $raised += $this->checkServerDisk($alerts);

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

    /**
     * COM-03: an ACTIVE node reporting a model the registry doesn't have
     * ACTIVE can't get any recognition answered (every request is refused).
     */
    private function checkNodeModels(AlertLifecycleService $alerts): int
    {
        $raised = 0;
        $nodes = AiNode::query()->where('status', DeviceStatus::ACTIVE)
            ->whereNotNull('model_code')->whereNotNull('model_version')->get();

        foreach ($nodes as $node) {
            if ($node->isModelCompatible() === false) {
                $raised += $alerts->triggerOnce(
                    type: 'AI_NODE_MODEL_MISMATCH',
                    severity: SystemAlert::SEVERITY_CRITICAL,
                    title: "AI node {$node->node_code} runs an unregistered model",
                    message: "Node reports {$node->model_code} {$node->model_version}, which is not an ACTIVE face embedding model; its recognition requests are refused.",
                    source: 'ai_node_model:'.$node->uuid,
                    metadata: ['ai_node_uuid' => $node->uuid, 'model_code' => $node->model_code, 'model_version' => $node->model_version],
                ) ? 1 : 0;
            }
        }

        return $raised;
    }

    /**
     * COM-03: node disk fills up with the offline event buffer and logs.
     */
    private function checkNodeDisks(AlertLifecycleService $alerts): int
    {
        $threshold = (float) config('ai_node.health.degraded_disk_usage', 90.0);
        $raised = 0;

        foreach (AiNode::query()->where('status', DeviceStatus::ACTIVE)->get() as $node) {
            $latest = AiNodeHealthLog::where('ai_node_id', $node->id)->latest('recorded_at')->latest('id')->first();
            if ($latest?->disk_usage !== null && $latest->disk_usage >= $threshold) {
                $raised += $alerts->triggerOnce(
                    type: 'AI_NODE_DISK_HIGH',
                    severity: SystemAlert::SEVERITY_WARNING,
                    title: "AI node {$node->node_code} disk almost full",
                    message: "Disk usage {$latest->disk_usage}% (threshold {$threshold}%). The offline event buffer needs free space.",
                    source: 'ai_node_disk:'.$node->uuid,
                    metadata: ['ai_node_uuid' => $node->uuid, 'disk_usage' => $latest->disk_usage],
                ) ? 1 : 0;
            }
        }

        return $raised;
    }

    /**
     * COM-05: the central server's own disk (local backups, exports, logs).
     */
    private function checkServerDisk(AlertLifecycleService $alerts, ?string $path = null): int
    {
        $path ??= storage_path();
        $free = $this->safely(fn () => disk_free_space($path));
        $total = $this->safely(fn () => disk_total_space($path));
        if (! is_float($free) || ! is_float($total) || $total <= 0) {
            return 0;
        }

        $freePercent = $free / $total * 100;
        $threshold = (float) config('backup.disk_min_free_percent', 10);
        if ($freePercent >= $threshold) {
            return 0;
        }

        return $alerts->triggerOnce(
            type: 'SERVER_DISK_LOW',
            severity: SystemAlert::SEVERITY_CRITICAL,
            title: 'Server disk almost full',
            message: sprintf('%.1f%% free (%.1f GiB) on the storage volume; threshold %.0f%%. Backups and exports may fail.', $freePercent, $free / 1073741824, $threshold),
            source: 'server_disk:storage',
            metadata: ['free_percent' => round($freePercent, 1), 'free_bytes' => (int) $free],
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
