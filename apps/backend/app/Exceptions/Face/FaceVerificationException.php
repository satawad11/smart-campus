<?php

namespace App\Exceptions\Face;

use RuntimeException;

/**
 * Domain Exception for Face Enrollment Verification (FACE-010).
 */
class FaceVerificationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'FACE_VERIFICATION_FAILED',
        public readonly int $statusCode = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
