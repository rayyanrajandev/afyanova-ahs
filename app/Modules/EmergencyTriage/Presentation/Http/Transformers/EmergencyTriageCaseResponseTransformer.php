<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Transformers;

class EmergencyTriageCaseResponseTransformer
{
    public static function transform(array $case): array
    {
        return [
            'id' => $case['id'] ?? null,
            'caseNumber' => $case['case_number'] ?? null,
            'patientId' => $case['patient_id'] ?? null,
            'admissionId' => $case['admission_id'] ?? null,
            'appointmentId' => $case['appointment_id'] ?? null,
            'assignedClinicianUserId' => $case['assigned_clinician_user_id'] ?? null,
            'arrivalAt' => $case['arrived_at'] ?? null,
            'triageLevel' => $case['triage_level'] ?? null,
            'chiefComplaint' => $case['chief_complaint'] ?? null,
            'vitalsSummary' => $case['vitals_summary'] ?? null,
            'triagedAt' => $case['triaged_at'] ?? null,
            'dispositionNotes' => $case['disposition_notes'] ?? null,
            'completedAt' => $case['completed_at'] ?? null,
            'status' => $case['status'] ?? null,
            'statusReason' => $case['status_reason'] ?? null,
            'createdAt' => $case['created_at'] ?? null,
            'updatedAt' => $case['updated_at'] ?? null,
        ];
    }
}
