<?php

namespace App\Services\Camera\Snapshot;

use App\Models\Camera;

/**
 * Default snapshot provider (CAM-002). Returns a clearly-synthetic 1x1 PNG
 * placeholder — never a fabricated "real" camera frame. Bound until a real
 * RTSP-backed provider is wired in once CAM-005/006/007 exist.
 */
class MockCameraSnapshotProvider implements CameraSnapshotProviderInterface
{
    // A minimal valid 1x1 transparent PNG — deterministic placeholder bytes,
    // never presented as a genuine capture.
    private const PLACEHOLDER_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function capture(Camera $camera, int $timeoutSeconds): CameraSnapshotResult
    {
        return CameraSnapshotResult::success(
            bytes: base64_decode(self::PLACEHOLDER_PNG_BASE64),
            contentType: 'image/png',
            source: 'MOCK',
        );
    }
}
