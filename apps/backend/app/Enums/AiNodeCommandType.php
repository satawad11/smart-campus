<?php

namespace App\Enums;

/**
 * The complete allowlist of remote operations an administrator may ask an
 * AI node to perform (COM-03). Adding a case is a reviewed code change;
 * no API accepts free-form command text.
 */
enum AiNodeCommandType: string
{
    /** Fetch the camera configuration now instead of waiting for the sync interval. */
    case RESYNC_CONFIG = 'RESYNC_CONFIG';

    /** Restart the node service (the container restart policy brings it back). */
    case RESTART_SERVICE = 'RESTART_SERVICE';
}
