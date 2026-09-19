<?php

namespace App\Enums;

/**
 * Source of a student academic-status change (system blueprint §3). Mirrors the
 * student_status_histories.source CHECK constraint. Domain history metadata —
 * not a security audit trail (AUTH-014) and not an access decision.
 */
enum StatusChangeSource: string
{
    case MANUAL = 'MANUAL';
    case IMPORT = 'IMPORT';
    case API = 'API';
    case SIS_SYNC = 'SIS_SYNC';
}
