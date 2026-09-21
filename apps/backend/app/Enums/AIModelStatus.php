<?php

namespace App\Enums;

/**
 * AI Model lifecycle status (database blueprint §5, Module 03).
 */
enum AIModelStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case DEPRECATED = 'DEPRECATED';
}
