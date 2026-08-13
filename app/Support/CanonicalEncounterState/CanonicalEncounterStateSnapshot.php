<?php

namespace App\Support\CanonicalEncounterState;

use Carbon\CarbonImmutable;

/**
 * Immutable result of one CanonicalEncounterStateResolver::resolve() call.
 * Read-only value object — nothing in this class writes anywhere.
 */
final readonly class CanonicalEncounterStateSnapshot
{
    /**
     * @param  array<int, array{code: string, severity: string, message: string}>  $detectedConflicts
     * @param  array<int, string>  $partialFailures  which internal reads (if any) failed and were
     *                                                degraded to UNKNOWN under the fail-closed rule
     *                                                (design doc 01, §2.4) — for diagnostics only
     */
    public function __construct(
        public string $encounterId,
        public CanonicalEncounterState $canonicalState,
        public CanonicalNoteDimension $noteDimension,
        public CanonicalOrdersDimension $ordersDimension,
        public CanonicalBillingDimension $billingDimension,
        public CanonicalDiagnosisDimension $diagnosisDimension,
        public string $matchedRuleId,
        public array $detectedConflicts,
        public CarbonImmutable $computedAt,
        public string $legacyEncounterStatus,
        public array $partialFailures = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'encounter_id' => $this->encounterId,
            'canonical_state' => $this->canonicalState->value,
            'dimensions' => [
                'note' => $this->noteDimension->value,
                'orders' => $this->ordersDimension->value,
                'billing' => $this->billingDimension->value,
                'diagnosis' => $this->diagnosisDimension->value,
            ],
            'matched_rule_id' => $this->matchedRuleId,
            'legacy_encounter_status' => $this->legacyEncounterStatus,
            'detected_conflicts' => $this->detectedConflicts,
            'partial_failures' => $this->partialFailures,
            'computed_at' => $this->computedAt->toIso8601String(),
        ];
    }
}
