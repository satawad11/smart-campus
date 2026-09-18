<?php

namespace App\Http\Controllers;

use App\Support\HealthChecks;
use Illuminate\Http\JsonResponse;

/**
 * Health endpoints (FND-010).
 *
 * - liveness: cheap "is the app up" probe (Docker/Nginx/monitoring).
 * - per-dependency probes: database, redis, storage, ai.
 * - full: consolidated readiness across all dependencies (200 if all ok, else 503).
 *
 * Responses use the standard API envelope (PROJECT_RULES.md §6) and never
 * expose credentials or connection details.
 */
class HealthController extends Controller
{
    public function liveness(): JsonResponse
    {
        return $this->envelope(true, 'backend');
    }

    public function database(HealthChecks $checks): JsonResponse
    {
        return $this->envelope($checks->database(), 'database');
    }

    public function redis(HealthChecks $checks): JsonResponse
    {
        return $this->envelope($checks->redis(), 'redis');
    }

    public function storage(HealthChecks $checks): JsonResponse
    {
        return $this->envelope($checks->storage(), 'object-storage');
    }

    public function ai(HealthChecks $checks): JsonResponse
    {
        return $this->envelope($checks->aiService(), 'ai-service');
    }

    public function full(HealthChecks $checks): JsonResponse
    {
        $results = [
            'database' => $checks->database(),
            'redis' => $checks->redis(),
            'object_storage' => $checks->storage(),
            'ai_service' => $checks->aiService(),
        ];

        $allUp = ! in_array(false, $results, true);

        $checkStatuses = [];
        foreach ($results as $name => $up) {
            $checkStatuses[$name] = ['status' => $up ? 'ok' : 'down'];
        }

        return response()->json([
            'success' => $allUp,
            'message' => $allUp ? 'Success' : 'One or more dependencies are unavailable',
            'data' => [
                'status' => $allUp ? 'ok' : 'degraded',
                'service' => 'backend',
                'checks' => $checkStatuses,
                'time' => now()->toIso8601String(),
            ],
            'meta' => null,
        ], $allUp ? 200 : 503);
    }

    private function envelope(bool $up, string $service): JsonResponse
    {
        return response()->json([
            'success' => $up,
            'message' => $up ? 'Success' : ucfirst($service).' unavailable',
            'data' => [
                'status' => $up ? 'ok' : 'down',
                'service' => $service,
                'time' => now()->toIso8601String(),
            ],
            'meta' => null,
        ], $up ? 200 : 503);
    }
}
