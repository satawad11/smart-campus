<?php

namespace App\Enums;

/**
 * Student academic status (system blueprint §6). Mirrors the
 * student_profiles.academic_status CHECK constraint.
 *
 * This is authoritative registry/academic data — it must come from trusted
 * source data and must NEVER be inferred from a face or appearance (Module 03),
 * and it is NOT an access-control decision. Module 05 policy (not Module 02)
 * maps academic status + context → ALLOW / REVIEW / DENY / UNKNOWN. Do not add
 * access-decision values here.
 */
enum StudentAcademicStatus: string
{
    case ACTIVE = 'ACTIVE';
    case LEAVE = 'LEAVE';
    case SUSPENDED = 'SUSPENDED';
    case WITHDRAWN = 'WITHDRAWN';
    case GRADUATED = 'GRADUATED';
    case EXPELLED = 'EXPELLED';
    case UNKNOWN = 'UNKNOWN';
}
