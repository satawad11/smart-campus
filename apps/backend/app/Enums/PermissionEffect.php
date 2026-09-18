<?php

namespace App\Enums;

// Per-user permission override effect. Mirrors the user_permissions.effect
// CHECK constraint from AUTH-001.
enum PermissionEffect: string
{
    case ALLOW = 'ALLOW';
    case DENY = 'DENY';
}
