<?php

namespace App\Modules\Appointment\Presentation\Http\Transformers;

use App\Support\FinancialCoverage;

class AppointmentResponseTransformer
{
    public static function transform(array $appointment): array
    {
        return [
            'id' => $appointment['id'] ?? null,
            'appointmentNumber' => $appointment['appointment_number'] ?? null,
            'patientId' => $appointment['patient_id'] ?? null,
            'sourceAdmissionId' => $appointment['source_admission_id'] ?? null,
            'clinicianUserId' => $appointment['clinician_user_id'] ?? null,
            'department' => $appointment['department'] ?? null,
            'scheduledAt' => $appointment['scheduled_at'] ?? null,
            'durationMinutes' => $appointment['duration_minutes'] ?? null,
            'reason' => $appointment['reason'] ?? null,
            'notes' => $appointment['notes'] ?? null,
            'financialClass' => FinancialCoverage::normalize(
                isset($appointment['financial_coverage_type']) ? (string) $appointment['financial_coverage_type'] : null,
            ),
            'billingPayerContractId' => $appointment['billing_payer_contract_id'] ?? null,
            'coverageReference' => $appointment['coverage_reference'] ?? null,
            'coverageNotes' => $appointment['coverage_notes'] ?? null,
            'appointmentType' => $appointment['appointment_type'] ?? 'scheduled',
            'consultationType' => $appointment['consultation_type'] ?? 'new',
            'consultationTypeSource' => $appointment['consultation_type_source'] ?? 'auto',
            'consultationTypeOverrideReason' => $appointment['consultation_type_override_reason'] ?? null,
            'priorCompletedAppointmentId' => $appointment['prior_completed_appointment_id'] ?? null,
            'status' => $appointment['status'] ?? null,
            'statusReason' => $appointment['status_reason'] ?? null,
            'checkedInAt' => $appointment['checked_in_at'] ?? null,
            'triageVitalsSummary' => $appointment['triage_vitals_summary'] ?? null,
            'triageNotes' => $appointment['triage_notes'] ?? null,
            'triageCategory' => $appointment['triage_category'] ?? null,
            'triagedAt' => $appointment['triaged_at'] ?? null,
            'triagedByUserId' => $appointment['triaged_by_user_id'] ?? null,
            'triageOwnerUserId' => $appointment['triage_owner_user_id'] ?? null,
            'triageOwnerAssignedAt' => $appointment['triage_owner_assigned_at'] ?? null,
            'consultationStartedAt' => $appointment['consultation_started_at'] ?? null,
            'consultationOwnerUserId' => self::consultationOwnerUserId($appointment),
            'consultationOwnerAssignedAt' => $appointment['consultation_owner_assigned_at'] ?? null,
            'consultationTakeoverCount' => $appointment['consultation_takeover_count'] ?? 0,
            'createdAt' => $appointment['created_at'] ?? null,
            'updatedAt' => $appointment['updated_at'] ?? null,
        ];
    }

    /**
     * Older active consultations can exist without explicit ownership metadata.
     * Fall back to the assigned clinician so the UI and downstream workflows
     * still treat the active visit as clinician-owned.
     */
    private static function consultationOwnerUserId(array $appointment): ?int
    {
        $explicitOwnerUserId = (int) ($appointment['consultation_owner_user_id'] ?? 0);
        if ($explicitOwnerUserId > 0) {
            return $explicitOwnerUserId;
        }

        $status = strtolower(trim((string) ($appointment['status'] ?? '')));
        if ($status !== 'in_consultation') {
            return null;
        }

        $assignedClinicianUserId = (int) ($appointment['clinician_user_id'] ?? 0);

        return $assignedClinicianUserId > 0 ? $assignedClinicianUserId : null;
    }
}
