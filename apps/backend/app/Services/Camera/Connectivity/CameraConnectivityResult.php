<?php

namespace App\Services\Camera\Connectivity;

/**
 * Sanitized outcome of a camera connectivity probe (CAM-002). Never carries
 * raw exception messages, hostnames with credentials, or stack traces.
 */
final class CameraConnectivityResult
{
    public function __construct(
        public readonly bool $reachable,
        public readonly ?float $latencyMs = null,
        public readonly ?string $errorCode = null,
    ) {}

    public static function success(float $latencyMs): self
    {
        return new self(reachable: true, latencyMs: round($latencyMs, 2));
    }

    public static function failure(string $errorCode): self
    {
        return new self(reachable: false, errorCode: $errorCode);
    }

    /**
     * @return array{reachable: bool, latency_ms: ?float, error_code: ?string}
     */
    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'latency_ms' => $this->latencyMs,
            'error_code' => $this->errorCode,
        ];
    }
}
