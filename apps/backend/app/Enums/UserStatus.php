<?php

namespace App\Enums;

// User account status (database blueprint §4 / Module 01 doc). Mirrors the
// users.status CHECK constraint from AUTH-001.
enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case LOCKED = 'LOCKED';
    case DISABLED = 'DISABLED';
    case EXPIRED = 'EXPIRED';
}
