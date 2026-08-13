<?php

namespace App\Modules\Appointment\Domain\Services;

interface ConsultationClassificationServiceInterface
{
    /**
     * Automatically classify a new appointment as NEW or REVIEW based on
     * patient visit history and the configured follow-up policy.
     *
     * Returns an array with the following keys:
     *   - classification: 'new' | 'review'
     *   - source: 'auto'
     *   - policy: active policy snapshot used for the decision
     *   - prior_completed_appointment_id: string|null  – the triggering prior visit ID
     *   - reasoning: string  – human-readable explanation stored in the audit trail
     *
     * @return array{
     *     classification: string,
     *     source: string,
     *     prior_completed_appointment_id: string|null,
     *     reasoning: string,
     *     policy: array<string, mixed>,
     * }
     */
    public function classify(
        string $patientId,
        ?string $facilityId,
        string $scheduledAt,
        ?string $reason,
    ): array;
}
