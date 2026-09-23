<?php

namespace App\Services\Event;

use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;

/**
 * The outcome of matching an AccessContext against access_policies
 * (EVT-006) — everything EVT-007 needs to persist an AccessDecision, but
 * this result itself is not one: producing it never writes to the
 * database ("Event != Decision" extends here too — matching is a pure
 * read-only evaluation).
 */
final readonly class AccessPolicyMatchResult
{
    public function __construct(
        public AccessDecisionOutcome $decision,
        public string $reasonCode,
        public DecisionSource $decisionSource,
        public ?int $matchedPolicyId,
    ) {}
}
