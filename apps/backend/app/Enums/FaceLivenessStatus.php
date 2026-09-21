<?php

namespace App\Enums;

/**
 * Liveness / Presentation Attack Detection classification (FACE-015, docs/07-module-03-face-ai.md).
 */
enum FaceLivenessStatus: string
{
    case REAL = 'REAL';
    case SPOOF = 'SPOOF';
    case UNCERTAIN = 'UNCERTAIN';
}
