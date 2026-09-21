<?php

namespace App\Services\Camera\Connectivity;

/**
 * Real bounded-timeout TCP reachability probe (CAM-002).
 *
 * Uses stream_socket_client with an explicit timeout — never blocks
 * indefinitely. Only ever receives host/port (already stripped of
 * credentials by the caller); it never sees or logs the RTSP URL.
 */
class TcpConnectivityProber implements CameraConnectivityProberInterface
{
    public function probe(string $host, int $port, int $timeoutSeconds): CameraConnectivityResult
    {
        $start = microtime(true);
        $errno = 0;
        $errstr = '';

        $connection = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeoutSeconds,
        );

        if ($connection === false) {
            return CameraConnectivityResult::failure(
                $errno === 110 || $errno === 0 ? 'CAMERA_CONNECTION_TIMEOUT' : 'CAMERA_CONNECTION_REFUSED'
            );
        }

        fclose($connection);

        return CameraConnectivityResult::success((microtime(true) - $start) * 1000);
    }
}
