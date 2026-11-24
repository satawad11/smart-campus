<?php

namespace App\Enums;

/**
 * Smart Gate decision mode (GATE-001, ACR-VEH-01 decision D2). FACE_PRIMARY: the face is the
 * identity and a plate alone never produces ALLOW. No other mode is approved.
 */
enum GateMode: string
{
    case FACE_PRIMARY = 'FACE_PRIMARY';
}
