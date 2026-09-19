<?php

namespace App\Enums;

/**
 * Operational lifecycle for academic MASTER DATA (departments/programs/
 * classrooms). Mirrors the ACTIVE/INACTIVE CHECK constraints from STD-001.
 *
 * This is master-data status only — NOT PersonStatus (person record lifecycle)
 * and NOT StudentAcademicStatus (student academic state). INACTIVE means
 * disabled/retained, never hard-deleted.
 */
enum AcademicStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
