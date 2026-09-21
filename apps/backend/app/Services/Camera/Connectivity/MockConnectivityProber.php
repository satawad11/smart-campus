<?php

namespace App\Services\Camera\Connectivity;

/**
 * Deterministic connectivity prober for tests / environments with no
 * reachable camera network (CAM-002). Never performs a real network call.
 */
class MockConnectivityProber implements CameraConnectivityProberInterface
{
    public function __construct(
        private readonly bool $reachable = true,
        private readonly string $failureCode = 'CAMERA_CONNECTION_TIMEOUT',
    ) {}

    public function probe(string $host, int $port, int $timeoutSeconds): CameraConnectivityResult
    {
        return $this->reachable
            ? CameraConnectivityResult::success(12.5)
            : CameraConnectivityResult::failure($this->failureCode);
    }
}
