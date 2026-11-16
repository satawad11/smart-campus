<?php

namespace App\Enums;

/**
 * Identity document a visitor showed (Module 09, PPL-002). Only the last 4 characters of the number are
 * stored (visitor_profiles.id_document_last4), never the full number.
 */
enum IdDocumentType: string
{
    case NATIONAL_ID = 'NATIONAL_ID';
    case PASSPORT = 'PASSPORT';
    case DRIVING_LICENCE = 'DRIVING_LICENCE';
    case OTHER = 'OTHER';
}
