<?php

namespace App\Modules\Admission\Presentation\Http\Transformers;

use App\Support\FinancialCoverage;

class AdmissionResponseTransformer
{
    public static function transform(array $admission): array
    {
        return [
            'id' => $admission['id'] ?? null,
            'admissionNumber' => $admission['admission_number'] ?? null,
            'patientId' => $admission['patient_id'] ?? null,
            'appointmentId' => $admission['appointment_id'] ?? null,
            'attendingClinicianUserId' => $admission['attending_clinician_user_id'] ?? null,
            'ward' => $admission['ward'] ?? null,
            'bed' => $admission['bed'] ?? null,
            'bedResourceId' => $admission['bed_resource_id'] ?? null,
            'bedResource' => self::bedResourceSummary(
                is_array($admission['bed_resource'] ?? null) ? $admission['bed_resource'] : null,
            ),
            'admittedAt' => $admission['admitted_at'] ?? null,
            'dischargedAt' => $admission['discharged_at'] ?? null,
            'admissionReason' => $admission['admission_reason'] ?? null,
            'notes' => $admission['notes'] ?? null,
            'financialClass' => FinancialCoverage::normalize(
                isset($admission['financial_coverage_type']) ? (string) $admission['financial_coverage_type'] : null,
            ),
            'billingPayerContractId' => $admission['billing_payer_contract_id'] ?? null,
            'coverageReference' => $admission['coverage_reference'] ?? null,
            'coverageNotes' => $admission['coverage_notes'] ?? null,
            'status' => $admission['status'] ?? null,
            'statusReason' => $admission['status_reason'] ?? null,
            'dischargeDestination' => $admission['discharge_destination'] ?? null,
            'followUpPlan' => $admission['follow_up_plan'] ?? null,
            'createdAt' => $admission['created_at'] ?? null,
            'updatedAt' => $admission['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $resource
     * @return array{id: string, code: string|null, name: string|null, wardName: string|null, bedNumber: string|null}|null
     */
    private static function bedResourceSummary(?array $resource): ?array
    {
        if ($resource === null || ($resource['id'] ?? null) === null) {
            return null;
        }

        return [
            'id' => $resource['id'],
            'code' => $resource['code'] ?? null,
            'name' => $resource['name'] ?? null,
            'wardName' => $resource['ward_name'] ?? null,
            'bedNumber' => $resource['bed_number'] ?? null,
        ];
    }
}
