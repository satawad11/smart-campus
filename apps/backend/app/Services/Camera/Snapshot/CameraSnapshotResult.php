<?php

namespace App\Services\Camera\Snapshot;

/**
 * Outcome of a snapshot capture attempt (CAM-002). No raw credentials, no
 * internal storage paths — only a safe content-type/bytes pair or an error.
 */
final class CameraSnapshotResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $contentType = null,
        public readonly ?string $bytes = null,
        public readonly ?string $source = null,
        public readonly ?string $errorCode = null,
    ) {}

    public static function success(string $bytes, string $contentType, string $source): self
    {
        return new self(success: true, contentType: $contentType, bytes: $bytes, source: $source);
    }

    public static function failure(string $errorCode): self
    {
        return new self(success: false, errorCode: $errorCode);
    }
}
