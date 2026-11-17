<?php

namespace App\Enums;

/**
 * Outcome of correlating the events of one Smart Gate pass (GATE-003, ACR-VEH-01 §7).
 *
 * Partitioned by which events the pass has:
 * - MATCHED: face and plate events, both recognised, and the person is linked to the vehicle;
 * - MISMATCH: face and plate events, both recognised, but the person is not linked to the vehicle;
 * - UNKNOWN: face and plate events, but the face or the plate (or both) was not recognised;
 * - FACE_ONLY: a face event and no plate event in the window;
 * - PLATE_ONLY: a plate event and no face event in the window.
 *
 * This is a correlation fact, not an access decision: the decision stays in access_decisions.
 */
enum GateMatchResult: string
{
    case MATCHED = 'MATCHED';
    case MISMATCH = 'MISMATCH';
    case FACE_ONLY = 'FACE_ONLY';
    case PLATE_ONLY = 'PLATE_ONLY';
    case UNKNOWN = 'UNKNOWN';

    public function needsFaceEvent(): bool
    {
        return $this !== self::PLATE_ONLY;
    }

    public function needsPlateEvent(): bool
    {
        return $this !== self::FACE_ONLY;
    }

    /** MATCHED and MISMATCH are only possible when both the person and the vehicle are known. */
    public function needsPersonAndVehicle(): bool
    {
        return $this === self::MATCHED || $this === self::MISMATCH;
    }
}
