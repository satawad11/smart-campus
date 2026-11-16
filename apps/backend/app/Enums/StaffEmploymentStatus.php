<?php

namespace App\Enums;

/**
 * Teacher/staff employment status (Module 09, PPL-002). Mirrors the staff_profiles.employment_status
 * CHECK constraint. Registry data, never an access decision: Module 05 policy maps it to ALLOW/REVIEW.
 */
enum StaffEmploymentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case LEAVE = 'LEAVE';
    case SUSPENDED = 'SUSPENDED';
    case RESIGNED = 'RESIGNED';
    case RETIRED = 'RETIRED';
}
