<?php

namespace App\Enums;

/**
 * Which way traffic passes a Smart Gate (GATE-001, ACR-VEH-01).
 */
enum GateDirection: string
{
    case IN = 'IN';
    case OUT = 'OUT';
    case BOTH = 'BOTH';
}
