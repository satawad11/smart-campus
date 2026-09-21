<?php

namespace App\Enums;

/**
 * Camera/AI-Node administrative enable/disable state (CAM-001).
 *
 * This is the device's management-level lifecycle (is it configured to be
 * used at all), set by an administrator — distinct from live operational
 * health (see HealthStatus), which is observed telemetry computed later
 * (CAM-013/CAM-014) and never inferred from this column alone.
 */
enum DeviceStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
