<?php

namespace App\Services\Camera\Snapshot;

use App\Models\Camera;

/**
 * Captures a single controlled frame from a camera (CAM-002).
 *
 * Real RTSP frame acquisition (video decode) requires the VideoSource/RTSP
 * source pipeline built in CAM-005/006/007. Until that exists, only the mock
 * provider is bound — it never fabricates a real captured frame; it returns
 * a clearly-synthetic placeholder image. No enrollment or recognition is
 * ever triggered by a snapshot.
 */
interface CameraSnapshotProviderInterface
{
    public function capture(Camera $camera, int $timeoutSeconds): CameraSnapshotResult;
}
