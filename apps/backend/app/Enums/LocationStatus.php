<?php

namespace App\Enums;

/**
 * Site/Zone RECORD lifecycle (CAM-001, database blueprint §6).
 *
 * Mirrors the master-data ACTIVE/INACTIVE pattern already used for
 * Department/Program/Classroom (AcademicStatus). This is the structural
 * location's own lifecycle — never a camera/AI-node live health state
 * (see HealthStatus) and never an access-control decision.
 */
enum LocationStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
