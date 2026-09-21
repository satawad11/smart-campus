<?php

namespace App\Enums;

/**
 * Recognition Classification outcome (FACE-013, docs/02-api-contract.md §8).
 *
 * Invariant:
 * - This represents biometric recognition classification only.
 * - It is strictly distinct from access policy decisions (ALLOW/REVIEW/DENY - Module 05).
 */
enum FaceRecognitionClassification: string
{
    case MATCH = 'MATCH';
    case REVIEW = 'REVIEW';
    case UNKNOWN = 'UNKNOWN';
}
