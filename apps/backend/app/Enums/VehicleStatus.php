<?php

namespace App\Enums;

/**
 * Registry status of a vehicle (VEH-002, ACR-VEH-01). BLOCKED marks a known vehicle that a
 * Smart Gate policy denies (decision D2). Registry data only — not an access decision.
 */
enum VehicleStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case BLOCKED = 'BLOCKED';
}
