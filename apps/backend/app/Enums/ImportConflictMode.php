<?php

namespace App\Enums;

/**
 * Conflict handling policies for student imports (STD-014).
 *
 * - CREATE_ONLY: Rejects any existing student code with DUPLICATE_STUDENT_CODE.
 * - SKIP_EXISTING: Skips rows whose student_code and identity match existing records.
 * - UPDATE_EXISTING: Updates academic profile for verified matching identity; rejects identity mismatches.
 */
enum ImportConflictMode: string
{
    case CREATE_ONLY = 'CREATE_ONLY';
    case SKIP_EXISTING = 'SKIP_EXISTING';
    case UPDATE_EXISTING = 'UPDATE_EXISTING';
}
