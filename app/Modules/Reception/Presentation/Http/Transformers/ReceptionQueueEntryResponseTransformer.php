<?php

namespace App\Modules\Reception\Presentation\Http\Transformers;

use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;

class ReceptionQueueEntryResponseTransformer
{
    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function transform(array $entry): array
    {
        $status = $entry['status'] ?? null;

        // Delegated to PatientFlowStep (2026-08-16 flow audit): this branching
        // used to be one of three near-identical copies, alongside
        // NurseQueueController::deriveVisitStage() and
        // GetActiveVisitJourneyUseCase::deriveAppointmentStep(). They already
        // disagreed about nursing contact, which none of them knew about — so a
        // nurse actively with a patient still read as "waiting" on every board.
        $stage = is_string($status)
            ? (PatientFlowStep::fromAppointmentStatus(
                status: $status,
                hasTriageOwner: ! empty($entry['triageOwnerUserId']),
                hasConsultationStarted: ! empty($entry['consultationStartedAt']),
                hasNursingContact: ! empty($entry['nursingContactUserId']),
            )?->value ?? $status)
            : $status;

        return [
            'appointmentId' => $entry['appointmentId'] ?? null,
            'appointmentNumber' => $entry['appointmentNumber'] ?? null,
            'status' => $status,
            'stage' => $stage,
            'patientId' => $entry['patientId'] ?? null,
            'patientName' => $entry['patientName'] ?? null,
            'patientNumber' => $entry['patientNumber'] ?? null,
            'department' => $entry['department'] ?? null,
            'clinicianUserId' => $entry['clinicianUserId'] ?? null,
            'triageOwnerUserId' => $entry['triageOwnerUserId'] ?? null,
            'triageOwnerAssignedAt' => $entry['triageOwnerAssignedAt']?->toIso8601String(),
            'consultationOwnerUserId' => $entry['consultationOwnerUserId'] ?? null,
            'consultationStartedAt' => $entry['consultationStartedAt']?->toIso8601String(),
            'nursingContactUserId' => $entry['nursingContactUserId'] ?? null,
            'nursingContactStartedAt' => $entry['nursingContactStartedAt']?->toIso8601String(),
            'hasSignedConsultationNote' => $entry['hasSignedConsultationNote'] ?? false,
            'consultationStep' => $entry['consultationStep'] ?? null,
            'arrivalMode' => $entry['arrivalMode'] ?? null,
            'tier' => $entry['tier'] ?? null,
            'queuePosition' => $entry['queuePosition'] ?? null,
            'waitStartedAt' => $entry['waitStartedAt']?->toIso8601String(),
            'waitMinutes' => $entry['waitMinutes'] ?? null,
        ];
    }
}
