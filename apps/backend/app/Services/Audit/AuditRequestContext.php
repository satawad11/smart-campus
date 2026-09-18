<?php

namespace App\Services\Audit;

use Illuminate\Http\Request;

/**
 * Data transfer object capturing ambient HTTP request metadata for audit logging.
 */
readonly class AuditRequestContext
{
    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
    ) {}

    public static function fromRequest(?Request $request): self
    {
        if ($request === null) {
            return new self;
        }

        return new self(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-ID')
        );
    }
}
