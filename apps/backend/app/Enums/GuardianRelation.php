<?php

namespace App\Enums;

/**
 * Relation of a guardian to a student (Module 09, PPL-002). Mirrors guardian_links.relation.
 */
enum GuardianRelation: string
{
    case FATHER = 'FATHER';
    case MOTHER = 'MOTHER';
    case GUARDIAN = 'GUARDIAN';
    case OTHER = 'OTHER';
}
