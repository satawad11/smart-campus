<?php

namespace App\Exceptions\Face;

use RuntimeException;

class FaceEnrollmentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'FACE_ENROLLMENT_ERROR',
        public readonly int $statusCode = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
