<?php

namespace App\Enums;

/**
 * Lifecycle of a visit (Module 09, PPL-002). Mirrors visits.status.
 */
enum VisitStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case CHECKED_IN = 'CHECKED_IN';
    case CHECKED_OUT = 'CHECKED_OUT';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';
}
