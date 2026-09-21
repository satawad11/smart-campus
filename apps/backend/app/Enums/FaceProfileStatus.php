<?php

namespace App\Enums;

/**
 * FaceProfile enrollment lifecycle status (database blueprint §5, Module 03).
 */
enum FaceProfileStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PENDING = 'PENDING';
    case REVOKED = 'REVOKED';
    case RE_ENROLL_REQUIRED = 'RE_ENROLL_REQUIRED';
}
