<?php

namespace App\Services\Camera;

use App\Enums\HealthStatus;
use App\Exceptions\ApiException;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\CameraHealthLog;
use Illuminate\Support\Facades\DB;

/**
 * Camera operational health collection (CAM-013).
 *
 * Records one raw telemetry snapshot per call and refreshes
 * Camera.last_seen_at — the receiving-a-report-at-all signal, same as
 * AiNodeService::heartbeat(). Unlike that heartbeat (which always logs
 * ONLINE and defers real classification to CAM-014's staleness sweep),
 * camera telemetry carries enough signal (dropped frames, reconnects, fps)
 * to classify DEGRADED synchronously, so this task does that itself.
 * OFFLINE is deliberately never derived here: a live push is definitionally
 * evidence the node believes it is still receiving frames from the camera;
 * genuine silence (no report at all) is a staleness concern for a separate
 * sweep, not this ingestion path.
 */
class CameraHealthService
{
    private const DEGRADED_MIN_FPS = 5.0;

    public function recordHealth(Camera $camera, AiNode $node, array $data): CameraHealthLog
    {
        if ($camera->ai_node_id !== $node->id) {
            throw ApiException::unprocessable(
                'CAMERA_NOT_ASSIGNED_TO_NODE',
                'This AI node is not assigned to the specified camera.',
            );
        }

        return DB::transaction(function () use ($camera, $data) {
            $camera->last_seen_at = now();
            $camera->save();

            return CameraHealthLog::create([
                'camera_id' => $camera->id,
                'status' => $this->classify($data)->value,
                'fps' => $data['fps'] ?? null,
                'latency_ms' => $data['latency_ms'] ?? null,
                'dropped_frames' => $data['dropped_frames'] ?? null,
                'resolution' => $data['resolution'] ?? null,
                'brightness_score' => $data['brightness_score'] ?? null,
                'blur_score' => $data['blur_score'] ?? null,
                'reconnect_count' => $data['reconnect_count'] ?? null,
                'recorded_at' => $data['recorded_at'] ?? now(),
            ]);
        });
    }

    private function classify(array $data): HealthStatus
    {
        $droppedFrames = $data['dropped_frames'] ?? 0;
        $reconnectCount = $data['reconnect_count'] ?? 0;
        $fps = $data['fps'] ?? null;

        if ($droppedFrames > 0 || $reconnectCount > 0 || ($fps !== null && $fps < self::DEGRADED_MIN_FPS)) {
            return HealthStatus::DEGRADED;
        }

        return HealthStatus::ONLINE;
    }
}
