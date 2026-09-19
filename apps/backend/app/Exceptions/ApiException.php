<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain/business-rule error carrying a stable API error code and HTTP status.
 * Rendered to the standard error envelope in bootstrap/app.php.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $errorCode, string $message): self
    {
        return new self($errorCode, $message, 409);
    }

    public static function unprocessable(string $errorCode, string $message): self
    {
        return new self($errorCode, $message, 422);
    }
}
