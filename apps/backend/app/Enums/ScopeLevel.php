<?php

namespace App\Enums;

/**
 * Authorization scope level (AUTH-009).
 *
 * Derived from a scope's (site_id, zone_id) pair:
 *   GLOBAL — site_id null,  zone_id null
 *   SITE   — site_id set,   zone_id null
 *   ZONE   — zone_id set    (site_id normally the owning site)
 */
enum ScopeLevel: string
{
    case GLOBAL = 'GLOBAL';
    case SITE = 'SITE';
    case ZONE = 'ZONE';
}
