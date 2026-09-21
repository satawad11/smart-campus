<?php

namespace App\Enums;

/**
 * Lifecycle of a per-node machine credential (CAM-004). Revocation is
 * permanent — a revoked credential is never reactivated; issue a new one.
 */
enum AiNodeCredentialStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';
}
