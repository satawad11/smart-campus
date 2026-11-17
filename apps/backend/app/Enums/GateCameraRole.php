<?php

namespace App\Enums;

/**
 * What a camera contributes to a Smart Gate (GATE-001, ACR-VEH-01 decision D3): faces, plates,
 * or both from one camera.
 */
enum GateCameraRole: string
{
    case FACE = 'FACE';
    case PLATE = 'PLATE';
    case BOTH = 'BOTH';

    public function readsFaces(): bool
    {
        return $this !== self::PLATE;
    }

    public function readsPlates(): bool
    {
        return $this !== self::FACE;
    }
}
