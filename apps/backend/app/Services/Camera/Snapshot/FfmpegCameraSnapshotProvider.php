<?php

namespace App\Services\Camera\Snapshot;

use App\Models\Camera;
use Illuminate\Support\Facades\Crypt;

/**
 * Real snapshot provider: decrypts the camera's stream URL/credentials in
 * memory and grabs one frame with ffmpeg. Plaintext credentials never leave
 * this call.
 */
class FfmpegCameraSnapshotProvider implements CameraSnapshotProviderInterface
{
    public function __construct(
        private readonly FfmpegFrameGrabber $grabber = new FfmpegFrameGrabber,
    ) {}

    public function capture(Camera $camera, int $timeoutSeconds): CameraSnapshotResult
    {
        $url = self::decrypt($camera->rtsp_url_encrypted);
        if ($url === null) {
            return CameraSnapshotResult::failure('CAMERA_NO_STREAM_CONFIGURED');
        }

        return $this->grabber->grab(
            FfmpegFrameGrabber::buildStreamUrl(
                $url,
                self::decrypt($camera->username_encrypted),
                self::decrypt($camera->password_encrypted),
            ),
            $timeoutSeconds,
        );
    }

    private static function decrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
