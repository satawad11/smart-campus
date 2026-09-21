<?php

namespace App\Enums;

/**
 * Face sample capture pose (database blueprint §5, Module 03).
 */
enum FaceSamplePose: string
{
    case FRONT = 'FRONT';
    case LEFT_SLIGHT = 'LEFT_SLIGHT';
    case RIGHT_SLIGHT = 'RIGHT_SLIGHT';
    case OTHER = 'OTHER';
}
