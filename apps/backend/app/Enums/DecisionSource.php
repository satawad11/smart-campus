<?php

namespace App\Enums;

/**
 * Origin of an access decision (EVT-001, database blueprint §7).
 *
 * SYSTEM: a deterministic non-policy outcome (e.g. recognition classified
 * UNKNOWN, so the decision is UNKNOWN before any policy is even evaluated).
 * POLICY: produced by the configured access-policy matcher (Module 05).
 * OFFICER: a human manual review decision — always a NEW row appended
 * alongside the original, never an edit/overwrite of it (docs/09:
 * "Officer review adds a new decision. It never overwrites system decision").
 */
enum DecisionSource: string
{
    case SYSTEM = 'SYSTEM';
    case POLICY = 'POLICY';
    case OFFICER = 'OFFICER';
}
