<?php

namespace App\Enums;

/**
 * Stable machine-readable action identifiers for security and audit events (AUTH-014).
 */
enum AuditAction: string
{
    // User management
    case USER_CREATED = 'USER_CREATED';
    case USER_UPDATED = 'USER_UPDATED';
    case USER_STATUS_CHANGED = 'USER_STATUS_CHANGED';
    case USER_PASSWORD_RESET = 'USER_PASSWORD_RESET';
    case USER_PASSWORD_CHANGED = 'USER_PASSWORD_CHANGED';

    // MFA lifecycle
    case MFA_ENABLED = 'MFA_ENABLED';
    case MFA_DISABLED = 'MFA_DISABLED';

    // Role & Permission management
    case ROLE_CREATED = 'ROLE_CREATED';
    case ROLE_UPDATED = 'ROLE_UPDATED';
    case ROLE_PERMISSION_MATRIX_UPDATED = 'ROLE_PERMISSION_MATRIX_UPDATED';

    // Session administration
    case SESSION_REVOKED = 'SESSION_REVOKED';
    case USER_SESSIONS_REVOKED = 'USER_SESSIONS_REVOKED';
    case OTHER_SESSIONS_REVOKED = 'OTHER_SESSIONS_REVOKED';
}
