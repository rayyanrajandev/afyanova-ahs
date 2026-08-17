<?php

namespace App\Modules\Encounter\Presentation\Http\Transformers;

use App\Modules\PatientFlow\Application\UseCases\ResolveVisitStagesUseCase;

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
    /**
     * @param  string|null  $visitStage  Pre-resolved flow step, supplied by a list caller that
     *   has already batched the lookup for the whole page. Null means resolve it here.
     */
    public static function transform(array $encounter, ?string $visitStage = null): array
    {
        $patient = $encounter['patient'] ?? null;
        $appointment = $encounter['appointment'] ?? null;
        $latestMedicalRecord = $encounter['latest_medical_record'] ?? null;

        $appointmentStatus = $appointment['status'] ?? null;
        $isTriaged = isset($appointment['triaged_at']) || in_array($appointmentStatus, ['waiting_provider', 'in_consultation', 'completed'], true);

        return [
            'id' => $encounter['id'] ?? null,
            'encounterNumber' => $encounter['encounter_number'] ?? null,
            'patientId' => $encounter['patient_id'] ?? null,
            'patientNumber' => $patient['patient_number'] ?? null,
            'patientName' => self::patientName($patient),
            'appointmentId' => $encounter['appointment_id'] ?? null,
            'appointmentStatus' => $appointmentStatus,
            /**
             * The server-resolved flow step (2026-08-16 flow audit). The
             * clinician queue used to invent its own rule for this —
             * `status === "open" && hasMedicalRecord` — which tracked "a note
             * exists" rather than "a doctor started", fired late, and knew
             * nothing about nursing pickup. It now renders whatever this says,
             * from the same PatientFlowStep resolver reception and nursing use.
             *
             * Resolved through ResolveVisitStagesUseCase so an open diagnostic
             * order is reflected too — a doctor who orders a lab test must not
             * keep reading "With Doctor" on their own queue while the patient is
             * standing in the lab. $visitStage lets a list caller batch that
             * lookup once for the page instead of once per row.
             */
            'visitStage' => $visitStage ?? app(ResolveVisitStagesUseCase::class)->forAppointment($appointment),
            'isTriaged' => $isTriaged,
            'triagedAt' => $appointment['triaged_at'] ?? null,
            'triageSummary' => $appointment['triage_vitals_summary'] ?? null,
            'arrivalMode' => $appointment['arrival_mode'] ?? null,
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
