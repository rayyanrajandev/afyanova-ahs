<?php

namespace App\Modules\PatientFlow\Presentation\Http\Transformers;

use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Support\Audit\AuditLogPresenter;

class PatientFlowTimelineEntryResponseTransformer
{
    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function transform(array $event): array
    {
        $toStep = PatientFlowStep::tryFrom((string) ($event['to_step'] ?? ''));
        $fromStep = PatientFlowStep::tryFrom((string) ($event['from_step'] ?? ''));

        // Reuses AuditLogPresenter rather than resolving users here: it already
        // owns the per-request actor cache and the system-vs-user distinction,
        // and the timeline renders alongside audit views that use its exact
        // `actor` shape.
        return AuditLogPresenter::enrich([
            'id' => $event['id'] ?? null,
            'patientId' => $event['patient_id'] ?? null,
            'appointmentId' => $event['appointment_id'] ?? null,
            'serviceRequestId' => $event['service_request_id'] ?? null,
            'encounterId' => $event['encounter_id'] ?? null,
            'fromStep' => $fromStep?->value,
            'fromStepLabel' => $fromStep?->label(),
            'toStep' => $toStep?->value,
            'toStepLabel' => $toStep?->label(),
            'isActiveContact' => $toStep?->isActiveContact() ?? false,
            'isTerminal' => $toStep?->isTerminal() ?? false,
            'actorRole' => $event['actor_role'] ?? null,
            'source' => $event['source'] ?? null,
            'reason' => $event['reason'] ?? null,
            'metadata' => $event['metadata'] ?? [],
            'occurredAt' => $event['occurred_at'] ?? null,
            // AuditLogPresenter keys off `action`; `source` is this log's
            // equivalent, so it is passed under both names rather than
            // duplicating the presenter's label lookup here.
            'action' => $event['source'] ?? null,
        ], [
            'actor_id' => $event['actor_user_id'] ?? null,
        ], self::SOURCE_LABELS);
    }

    /**
     * Staff-facing phrasing for each write path. Keys match
     * RecordPatientFlowTransitionService's `source` argument.
     */
    private const SOURCE_LABELS = [
        'appointment.status_updated' => 'Visit status updated',
        'triage.handoff_recorded' => 'Triage completed',
        'triage.claimed' => 'Triage started',
        'triage.claim_released' => 'Triage released',
        'clinician.start_consultation' => 'Doctor started consultation',
        'clinician.consultation_takeover' => 'Consultation taken over',
        'clinician.sent_for_diagnostics' => 'Sent for diagnostics',
        'nursing.patient_claimed' => 'Nurse started with patient',
        'nursing.patient_released' => 'Nurse finished with patient',
        'nursing.assessment_completed' => 'Nursing assessment completed',
        'nursing.vitals_recorded' => 'Vitals recorded',
        'laboratory.specimen_collected' => 'Specimen collected',
        'laboratory.testing_started' => 'Lab testing started',
        'laboratory.result_entered' => 'Lab result entered',
        'laboratory.result_verified' => 'Lab result verified',
        'nursing.returned_to_reception' => 'Returned to reception',
        'nursing.patient_admitted' => 'Admitted to ward',
        'clinician.patient_admitted' => 'Admitted to ward',
    ];
}
