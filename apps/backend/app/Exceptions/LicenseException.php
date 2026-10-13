<?php

namespace App\Exceptions;

/**
 * A license or revocation list was rejected (COM-02). Rendered through the
 * standard ApiException envelope (422) with a stable error code.
 */
class LicenseException extends ApiException
{
    public static function rejected(string $errorCode, string $message): self
    {
        return new self($errorCode, $message, 422);
    }
}
