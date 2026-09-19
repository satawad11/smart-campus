<?php

namespace App\Enums;

/**
 * Status of an import job (database blueprint §9).
 */
enum ImportJobStatus: string
{
    case PENDING = 'PENDING';
    case VALIDATING = 'VALIDATING';
    case VALIDATED = 'VALIDATED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
