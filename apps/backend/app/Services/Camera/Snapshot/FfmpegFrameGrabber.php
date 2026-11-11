<?php

namespace App\Services\Camera\Snapshot;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Grabs one real video frame from a camera stream with ffmpeg and returns it
 * as JPEG bytes. Used by the camera snapshot endpoint and by the pre-save
 * stream preview on the add-camera form.
 *
 * The stream URL may embed credentials, so it is never logged, audited or
 * echoed back: failures map to fixed error codes only.
 */
class FfmpegFrameGrabber
{
    /** Schemes an operator may point a camera at. No file:, no data:, no concat:. */
    private const ALLOWED_SCHEMES = ['rtsp', 'rtsps', 'http', 'https'];

    public function __construct(
        private readonly string $binary = 'ffmpeg',
    ) {}

    public static function isAvailable(string $binary = 'ffmpeg'): bool
    {
        try {
            return Process::timeout(5)->run([$binary, '-hide_banner', '-version'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Inserts username/password into the URL when they are given separately
     * and the URL has no userinfo of its own.
     */
    public static function buildStreamUrl(?string $url, ?string $username, ?string $password): ?string
    {
        $url = $url !== null ? trim($url) : null;
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        if (isset($parts['user']) || $username === null || $username === '') {
            return $url;
        }

        $userinfo = rawurlencode($username).($password !== null && $password !== '' ? ':'.rawurlencode($password) : '').'@';
        $prefix = $parts['scheme'].'://';

        return $prefix.$userinfo.substr($url, strlen($prefix));
    }

    public function grab(?string $url, int $timeoutSeconds): CameraSnapshotResult
    {
        if ($url === null || $url === '') {
            return CameraSnapshotResult::failure('CAMERA_NO_STREAM_CONFIGURED');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return CameraSnapshotResult::failure('CAMERA_STREAM_URL_INVALID');
        }

        $timeoutSeconds = max(2, $timeoutSeconds);
        $args = [$this->binary, '-hide_banner', '-loglevel', 'error', '-nostdin'];
        if (str_starts_with($scheme, 'rtsp')) {
            // TCP is far more reliable through NAT/VLANs than UDP; -timeout is
            // the socket I/O timeout in microseconds.
            $args = [...$args, '-rtsp_transport', 'tcp', '-timeout', (string) ($timeoutSeconds * 1_000_000)];
        } else {
            $args = [...$args, '-rw_timeout', (string) ($timeoutSeconds * 1_000_000)];
        }
        $args = [...$args, '-i', $url, '-frames:v', '1', '-vf', 'scale=min(1280\,iw):-2', '-q:v', '4', '-f', 'image2', '-vcodec', 'mjpeg', 'pipe:1'];

        try {
            $result = Process::timeout($timeoutSeconds + 5)->run($args);
        } catch (ProcessTimedOutException) {
            return CameraSnapshotResult::failure('CAMERA_SNAPSHOT_TIMEOUT');
        } catch (\Throwable) {
            return CameraSnapshotResult::failure('CAMERA_SNAPSHOT_UNAVAILABLE');
        }

        $bytes = $result->output();
        if ($result->successful() && $bytes !== '' && str_starts_with($bytes, "\xFF\xD8")) {
            return CameraSnapshotResult::success($bytes, 'image/jpeg', 'CAMERA');
        }

        return CameraSnapshotResult::failure(self::classify($result->errorOutput()));
    }

    /** Maps ffmpeg stderr to a fixed, non-leaking error code. */
    private static function classify(string $stderr): string
    {
        $s = strtolower($stderr);

        return match (true) {
            str_contains($s, '401') || str_contains($s, 'unauthorized') => 'CAMERA_AUTH_FAILED',
            str_contains($s, '404') || str_contains($s, 'not found') => 'CAMERA_STREAM_NOT_FOUND',
            str_contains($s, 'timed out') || str_contains($s, 'timeout') => 'CAMERA_SNAPSHOT_TIMEOUT',
            str_contains($s, 'connection refused') || str_contains($s, 'no route') || str_contains($s, 'unreachable') => 'CAMERA_UNREACHABLE',
            default => 'CAMERA_SNAPSHOT_FAILED',
        };
    }
}
