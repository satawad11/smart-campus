<?php

namespace App\Enums;

/**
 * Observed operational health of a camera or AI node (CAM-001 health log
 * tables; classification computed later by CAM-013/CAM-014).
 *
 * This is telemetry, never an access-control decision, and never a
 * substitute for the device's own administrative DeviceStatus.
 */
enum HealthStatus: string
{
    case ONLINE = 'ONLINE';
    case DEGRADED = 'DEGRADED';
    case OFFLINE = 'OFFLINE';
}
