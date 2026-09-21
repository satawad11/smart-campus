<?php

namespace App\Exceptions\Face;

use RuntimeException;

class FaceLifecycleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'FACE_LIFECYCLE_ERROR',
        public readonly int $statusCode = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
