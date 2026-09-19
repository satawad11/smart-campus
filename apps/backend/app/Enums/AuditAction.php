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

    // Module 02 — academic master data (STD-005)
    case DEPARTMENT_CREATED = 'DEPARTMENT_CREATED';
    case DEPARTMENT_UPDATED = 'DEPARTMENT_UPDATED';
    case PROGRAM_CREATED = 'PROGRAM_CREATED';
    case PROGRAM_UPDATED = 'PROGRAM_UPDATED';
    case CLASSROOM_CREATED = 'CLASSROOM_CREATED';
    case CLASSROOM_UPDATED = 'CLASSROOM_UPDATED';

    // Module 02 — student domain (STD-006)
    case STUDENT_CREATED = 'STUDENT_CREATED';
    case STUDENT_STATUS_CHANGED = 'STUDENT_STATUS_CHANGED';
}
