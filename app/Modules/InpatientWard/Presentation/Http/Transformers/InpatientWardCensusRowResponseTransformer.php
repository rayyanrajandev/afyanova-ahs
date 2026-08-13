<?php

namespace App\Modules\InpatientWard\Presentation\Http\Transformers;

class InpatientWardCensusRowResponseTransformer
{
    public static function transform(array $admission): array
    {
        $patient = is_array($admission['patient'] ?? null) ? $admission['patient'] : [];
        $patientName = trim(implode(' ', array_filter([
            trim((string) ($patient['first_name'] ?? '')),
            trim((string) ($patient['middle_name'] ?? '')),
            trim((string) ($patient['last_name'] ?? '')),
        ])));

        return [
            'id' => $admission['id'] ?? null,
            'admissionNumber' => $admission['admission_number'] ?? null,
            'patientId' => $admission['patient_id'] ?? null,
            'patientNumber' => $patient['patient_number'] ?? null,
            'patientName' => $patientName !== '' ? $patientName : null,
            'appointmentId' => $admission['appointment_id'] ?? null,
            'attendingClinicianUserId' => $admission['attending_clinician_user_id'] ?? null,
            'ward' => $admission['ward'] ?? null,
            'bed' => $admission['bed'] ?? null,
            'admittedAt' => $admission['admitted_at'] ?? null,
            'dischargedAt' => $admission['discharged_at'] ?? null,
            'admissionReason' => $admission['admission_reason'] ?? null,
            'notes' => $admission['notes'] ?? null,
            'status' => $admission['status'] ?? null,
            'statusReason' => $admission['status_reason'] ?? null,
            'createdAt' => $admission['created_at'] ?? null,
            'updatedAt' => $admission['updated_at'] ?? null,
        ];
    }
}