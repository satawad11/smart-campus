<?php

namespace App\Enums;

/**
 * How a person is linked to a vehicle (VEH-002, ACR-VEH-01).
 */
enum VehicleRelation: string
{
    case OWNER = 'OWNER';
    case DRIVER = 'DRIVER';
    case AUTHORIZED = 'AUTHORIZED';
}
