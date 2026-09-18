<?php

namespace App\Enums;

/**
 * Authentication result status (AUTH-004).
 *
 * Represents domain-level outcome of credential and account eligibility evaluation.
 */
enum AuthStatus: string
{
    case SUCCESS = 'SUCCESS';
    case MFA_REQUIRED = 'MFA_REQUIRED';
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    case ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';
    case ACCOUNT_DISABLED = 'ACCOUNT_DISABLED';
    case ACCOUNT_EXPIRED = 'ACCOUNT_EXPIRED';
}
