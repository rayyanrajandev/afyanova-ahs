<?php

namespace App\Modules\Encounter\Presentation\Http\Transformers;

class EncounterListItemResponseTransformer
{
    /**
     * Deliberately a separate transformer from EncounterResponseTransformer
     * (used for the single-encounter GET) rather than extending it — a list
     * row needs joined summary fields (patient, clinician, latest note
     * status) that the single-encounter endpoint has no reason to carry.
     *
     * @param  array<string, mixed>  $encounter
     * @return array<string, mixed>
     */
    public static function transform(array $encounter): array
    {
        $patient = $encounter['patient'] ?? null;
        $latestMedicalRecord = $encounter['latest_medical_record'] ?? null;

        return [
            'id' => $encounter['id'] ?? null,
            'encounterNumber' => $encounter['encounter_number'] ?? null,
            'patientId' => $encounter['patient_id'] ?? null,
            'patientNumber' => $patient['patient_number'] ?? null,
            'patientName' => self::patientName($patient),
            'appointmentId' => $encounter['appointment_id'] ?? null,
            'admissionId' => $encounter['admission_id'] ?? null,
            'primaryClinicianUserId' => $encounter['primary_clinician_user_id'] ?? null,
            'primaryClinicianName' => $encounter['primary_clinician']['name'] ?? null,
            'status' => $encounter['status'] ?? null,
            'statusReason' => $encounter['status_reason'] ?? null,
            'openedAt' => $encounter['opened_at'] ?? null,
            'closedAt' => $encounter['closed_at'] ?? null,
            'hasMedicalRecord' => $latestMedicalRecord !== null,
            'latestMedicalRecordStatus' => $latestMedicalRecord['status'] ?? null,
            'latestMedicalRecordType' => $latestMedicalRecord['record_type'] ?? null,
            'latestMedicalRecordNumber' => $latestMedicalRecord['record_number'] ?? null,
            'createdAt' => $encounter['created_at'] ?? null,
            'updatedAt' => $encounter['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $patient
     */
    private static function patientName(?array $patient): ?string
    {
        if ($patient === null) {
            return null;
        }

        $name = implode(' ', array_filter([
            $patient['first_name'] ?? null,
            $patient['middle_name'] ?? null,
            $patient['last_name'] ?? null,
        ]));

        return $name !== '' ? $name : null;
    }
}
