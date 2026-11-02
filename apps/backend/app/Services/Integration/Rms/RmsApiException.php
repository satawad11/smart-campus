<?php

namespace App\Services\Integration\Rms;

use RuntimeException;

/**
 * An RMS API Center call that did not return ok:true. $error is the API's
 * machine code (no_key, bad_key, ip, scope, https_required, rate, off, ...,
 * or "connection"/"invalid_response" for transport problems); the message
 * is safe to log (it never contains the key).
 */
class RmsApiException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message);
    }
}
