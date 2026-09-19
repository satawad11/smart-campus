<?php

namespace App\Enums;

// Person type (system blueprint §5). Mirrors the persons.person_type CHECK
// constraint. A Person is the real-world identity; a STUDENT person type does
// not imply a login account or an academic profile.
enum PersonType: string
{
    case STUDENT = 'STUDENT';
    case TEACHER = 'TEACHER';
    case STAFF = 'STAFF';
    case PARENT = 'PARENT';
    case VISITOR = 'VISITOR';
    case CONTRACTOR = 'CONTRACTOR';
    case ALUMNI = 'ALUMNI';
    case OTHER = 'OTHER';
}
