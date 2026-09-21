<?php

namespace App\Enums;

/**
 * Status of a human duplicate-face review workflow item (FACE-011).
 */
enum DuplicateReviewStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED_DUPLICATE = 'CONFIRMED_DUPLICATE';
    case NOT_DUPLICATE = 'NOT_DUPLICATE';
    case DISMISSED = 'DISMISSED';
}
