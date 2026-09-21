<?php

namespace App\Services\Camera\Connectivity;

/**
 * Probes network reachability of a camera's stream endpoint (CAM-002).
 *
 * This performs a bounded-timeout TCP reachability check only — it does not
 * attempt an RTSP handshake or decode video. Full RTSP source lifecycle
 * (connect/read/reconnect) is CAM-005/CAM-006.
 */
interface CameraConnectivityProberInterface
{
    /**
     * @param  string  $host  Camera host/IP (never a full credentialed URL).
     * @param  int  $port  Camera stream port.
     * @param  int  $timeoutSeconds  Bounded connect timeout.
     */
    public function probe(string $host, int $port, int $timeoutSeconds): CameraConnectivityResult;
}
