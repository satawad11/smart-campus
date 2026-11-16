<?php

namespace App\Enums;

/**
 * Type of an import job (database blueprint §9, module 02).
 */
enum ImportJobType: string
{
    case STUDENT = 'STUDENT';
    case STAFF = 'STAFF'; // Module 09, PPL-004
}
