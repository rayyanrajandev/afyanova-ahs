<?php

namespace App\Modules\Encounter\Presentation\Http\Transformers;

use App\Modules\Appointment\Presentation\Http\Transformers\AppointmentResponseTransformer;
use App\Modules\Laboratory\Presentation\Http\Transformers\LaboratoryOrderResponseTransformer;
use App\Modules\MedicalRecord\Presentation\Http\Transformers\MedicalRecordResponseTransformer;
use App\Modules\Pharmacy\Presentation\Http\Transformers\PharmacyOrderResponseTransformer;
use App\Modules\Radiology\Presentation\Http\Transformers\RadiologyOrderResponseTransformer;
use App\Modules\TheatreProcedure\Presentation\Http\Transformers\TheatreProcedureResponseTransformer;

use App\Support\ClinicalOrders\ClinicalOrderPaymentEnricher;

class EncounterWorkspaceResponseTransformer
{
    /**
     * @param  array<string, mixed>  $workspace
     * @return array<string, mixed>
     */
    public static function transform(array $workspace): array
    {
        $encounter = is_array($workspace['encounter'] ?? null) ? $workspace['encounter'] : [];
        $patient = is_array($workspace['patient'] ?? null) ? $workspace['patient'] : null;
        $appointment = is_array($workspace['appointment'] ?? null) ? $workspace['appointment'] : null;
        $admission = is_array($workspace['admission'] ?? null) ? $workspace['admission'] : null;
        $primaryMedicalRecord = is_array($workspace['primaryMedicalRecord'] ?? null)
            ? $workspace['primaryMedicalRecord']
            : null;

        $rawLabOrders = is_array($workspace['laboratoryOrders'] ?? null) ? $workspace['laboratoryOrders'] : [];
        $transformedLabOrders = array_map(
            static fn (array $order): array => LaboratoryOrderResponseTransformer::transform($order),
            $rawLabOrders,
        );
        $enrichedLabOrders = ClinicalOrderPaymentEnricher::attachToTransformedOrders($rawLabOrders, $transformedLabOrders);

        $rawRadOrders = is_array($workspace['radiologyOrders'] ?? null) ? $workspace['radiologyOrders'] : [];
        $transformedRadOrders = array_map(
            static fn (array $order): array => RadiologyOrderResponseTransformer::transform($order),
            $rawRadOrders,
        );
        $enrichedRadOrders = ClinicalOrderPaymentEnricher::attachToTransformedOrders(
            $rawRadOrders,
            $transformedRadOrders,
            \App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind::RADIOLOGY_ORDER,
        );

        $rawPharmOrders = is_array($workspace['pharmacyOrders'] ?? null) ? $workspace['pharmacyOrders'] : [];
        $transformedPharmOrders = array_map(
            static fn (array $order): array => PharmacyOrderResponseTransformer::transform($order),
            $rawPharmOrders,
        );
        $enrichedPharmOrders = ClinicalOrderPaymentEnricher::attachToTransformedOrders(
            $rawPharmOrders,
            $transformedPharmOrders,
            \App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind::PHARMACY_ORDER,
        );

        return [
            'encounter' => EncounterResponseTransformer::transform($encounter),
            'patient' => $patient !== null ? self::transformPatientSummary($patient) : null,
            'appointment' => $appointment !== null
                ? AppointmentResponseTransformer::transform($appointment)
                : null,
            'admission' => $admission !== null ? self::transformAdmissionSummary($admission) : null,
            'diagnoses' => array_map(
                static fn (array $diagnosis): array => EncounterDiagnosisResponseTransformer::transform($diagnosis),
                is_array($workspace['diagnoses'] ?? null) ? $workspace['diagnoses'] : [],
            ),
            'primaryMedicalRecord' => $primaryMedicalRecord !== null
                ? MedicalRecordResponseTransformer::transform($primaryMedicalRecord)
                : null,
            'laboratoryOrders' => $enrichedLabOrders,
            // C-8 (reports/clinical-note-audit/15-critical-system-integrity-review.md):
            // the total pending count, independent of the CARE_ARTIFACT_LIMIT cap on
            // the list above, so a "+N more pending" affordance can be shown without
            // guessing from the capped list's length.
            'laboratoryOrdersPendingCount' => (int) ($workspace['laboratoryOrdersPendingCount'] ?? 0),
            'pharmacyOrders' => $enrichedPharmOrders,
            'pharmacyOrdersPendingCount' => (int) ($workspace['pharmacyOrdersPendingCount'] ?? 0),
            'radiologyOrders' => $enrichedRadOrders,
            'radiologyOrdersPendingCount' => (int) ($workspace['radiologyOrdersPendingCount'] ?? 0),
            'theatreProcedures' => array_map(
                static fn (array $procedure): array => TheatreProcedureResponseTransformer::transform($procedure),
                is_array($workspace['theatreProcedures'] ?? null) ? $workspace['theatreProcedures'] : [],
            ),
            'theatreProceduresPendingCount' => (int) ($workspace['theatreProceduresPendingCount'] ?? 0),
            'closeReadiness' => EncounterCloseReadinessResponseTransformer::transform(
                is_array($workspace['closeReadiness'] ?? null) ? $workspace['closeReadiness'] : null,
            ),
            'canEdit' => (bool) ($workspace['canEdit'] ?? true),
        ];
    }

    /**
     * Minimal — only what's needed to derive an admission-based encounter's
     * "location" (ward/bed) for display; not the full admission record.
     *
     * @param  array<string, mixed>  $admission
     * @return array<string, mixed>
     */
    private static function transformAdmissionSummary(array $admission): array
    {
        return [
            'id' => $admission['id'] ?? null,
            'ward' => $admission['ward'] ?? null,
            'bed' => $admission['bed'] ?? null,
        ];
    }

    /**
     * Deliberately minimal — this bundle is read by clinical/ordering staff for
     * identification, not the patient's own chart. Full PII (national ID, next
     * of kin, address, contact details — see PatientResponseTransformer) has
     * no reason to travel in a workspace-header payload.
     *
     * @param  array<string, mixed>  $patient
     * @return array<string, mixed>
     */
    private static function transformPatientSummary(array $patient): array
    {
        return [
            'id' => $patient['id'] ?? null,
            'patientNumber' => $patient['patient_number'] ?? null,
            'firstName' => $patient['first_name'] ?? null,
            'middleName' => $patient['middle_name'] ?? null,
            'lastName' => $patient['last_name'] ?? null,
            'gender' => $patient['gender'] ?? null,
            'dateOfBirth' => $patient['date_of_birth'] ?? null,
        ];
    }
}
