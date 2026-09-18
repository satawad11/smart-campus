<?php

namespace App\Enums;

// Role status. Mirrors the roles.status CHECK constraint from AUTH-001.
enum RoleStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DISABLED = 'DISABLED';
}
