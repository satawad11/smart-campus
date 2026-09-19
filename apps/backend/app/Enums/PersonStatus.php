<?php

namespace App\Enums;

/**
 * Person RECORD lifecycle (STD-002). Mirrors the persons.status CHECK constraint.
 *
 * This describes the identity record only — it is NOT student academic status
 * (ACTIVE/LEAVE/SUSPENDED/WITHDRAWN/GRADUATED/EXPELLED/UNKNOWN, which belong to
 * student_profiles in STD-003) and NOT an access-control decision.
 */
enum PersonStatus: string
{
    case ACTIVE = 'ACTIVE';       // normal operational identity record
    case INACTIVE = 'INACTIVE';   // retained but no longer operationally active
    case ARCHIVED = 'ARCHIVED';   // retained for historical/reference purposes
}
