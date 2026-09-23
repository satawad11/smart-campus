<?php

namespace App\Enums;

/**
 * Access policy decision outcome (EVT-001, database blueprint §7,
 * docs/09-module-05-event-policy-monitor.md). This is the actual
 * access-control decision — exclusively Module 05's concern.
 *
 * Strictly distinct from FaceRecognitionClassification (MATCH/REVIEW/
 * UNKNOWN, Module 03's biometric perception outcome): a MATCH can still
 * decide REVIEW or DENY here (e.g. a matched-but-suspended student), and an
 * UNKNOWN classification always decides UNKNOWN here — the two enums
 * share the word REVIEW/UNKNOWN but represent different questions
 * (who is this? vs what happens next?) and must never be conflated.
 */
enum AccessDecisionOutcome: string
{
    case ALLOW = 'ALLOW';
    case REVIEW = 'REVIEW';
    case DENY = 'DENY';
    case UNKNOWN = 'UNKNOWN';
}
