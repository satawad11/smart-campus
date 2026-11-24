<?php

namespace App\Enums;

/**
 * Vehicle body type (VEH-002, ACR-VEH-01). Mirrors the vehicles.vehicle_type constraint.
 */
enum VehicleType: string
{
    case CAR = 'CAR';
    case MOTORCYCLE = 'MOTORCYCLE';
    case PICKUP = 'PICKUP';
    case VAN = 'VAN';
    case TRUCK = 'TRUCK';
    case BUS = 'BUS';
    case OTHER = 'OTHER';
}
