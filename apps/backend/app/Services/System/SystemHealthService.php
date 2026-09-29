<?php

namespace App\Services\System;

use App\Models\AiNode;
use App\Models\Camera;
use App\Models\SystemAlert;
use App\Support\HealthChecks;
use Carbon\Carbon;

class SystemHealthService
{
    public function __construct(
        protected HealthChecks $healthChecks
    ) {}

    /**
     * Get aggregate health status across all infrastructure and edge layers.
     *
     * @return array<string, mixed>
     */
    public function getAggregateHealth(): array
    {
        $dbUp = $this->healthChecks->database();
        $redisUp = $this->healthChecks->redis();
        $storageUp = $this->healthChecks->storage();
        $aiServiceUp = $this->healthChecks->aiService();

        // Edge layers
        $totalCameras = Camera::count();
        $activeCameras = Camera::where('status', 'ACTIVE')->count();
        $totalAiNodes = AiNode::count();
        $onlineAiNodes = AiNode::where('status', 'ONLINE')->count();

        // Alerts summary
        $openAlertsCount = SystemAlert::where('status', SystemAlert::STATUS_OPEN)->count();
        $criticalAlertsCount = SystemAlert::where('status', SystemAlert::STATUS_OPEN)
            ->where('severity', SystemAlert::SEVERITY_CRITICAL)
            ->count();

        $overallStatus = 'HEALTHY';
        if (! $dbUp || ! $redisUp || $criticalAlertsCount > 0) {
            $overallStatus = 'CRITICAL';
        } elseif (! $storageUp || ! $aiServiceUp || ($totalCameras > 0 && $activeCameras === 0) || ($totalAiNodes > 0 && $onlineAiNodes === 0)) {
            $overallStatus = 'DEGRADED';
        }

        return [
            'status' => $overallStatus,
            'timestamp' => Carbon::now()->toIso8601String(),
            'core_services' => [
                'backend' => ['status' => 'UP'],
                'database' => ['status' => $dbUp ? 'UP' : 'DOWN'],
                'redis' => ['status' => $redisUp ? 'UP' : 'DOWN'],
                'storage' => ['status' => $storageUp ? 'UP' : 'DOWN'],
                'ai_service' => ['status' => $aiServiceUp ? 'UP' : 'DOWN'],
            ],
            'edge_devices' => [
                'cameras' => [
                    'total' => $totalCameras,
                    'active' => $activeCameras,
                    'offline' => max(0, $totalCameras - $activeCameras),
                ],
                'ai_nodes' => [
                    'total' => $totalAiNodes,
                    'online' => $onlineAiNodes,
                    'offline' => max(0, $totalAiNodes - $onlineAiNodes),
                ],
            ],
            'alerts' => [
                'open_total' => $openAlertsCount,
                'open_critical' => $criticalAlertsCount,
            ],
        ];
    }
}
