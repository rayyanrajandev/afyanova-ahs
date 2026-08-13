<?php

namespace App\Modules\Admission\Application\UseCases;

use App\Modules\Admission\Application\Exceptions\AppointmentNotEligibleForAdmissionException;
use App\Modules\Admission\Application\Exceptions\PatientNotEligibleForAdmissionException;
use App\Modules\Admission\Domain\Repositories\AdmissionAuditLogRepositoryInterface;
use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\Admission\Domain\Services\AdmissionPlacementLookupServiceInterface;
use App\Modules\Admission\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Admission\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\FinancialCoverage;

class UpdateAdmissionUseCase
{
    public function __construct(
        private readonly AdmissionAuditLogRepositoryInterface $auditLogRepository,
        private readonly AdmissionRepositoryInterface $admissionRepository,
        private readonly AdmissionPlacementLookupServiceInterface $admissionPlacementLookupService,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->admissionRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $patientId = (string) ($payload['patient_id'] ?? $existing['patient_id']);
        $eligibility = $this->patientLookupService->findAdmissionEligibilityById($patientId);
        if ($eligibility === null || ($eligibility['status'] ?? null) !== 'active') {
            throw new PatientNotEligibleForAdmissionException(
                'Admission can only be assigned to an active patient.',
            );
        }

        $appointmentId = $payload['appointment_id'] ?? ($existing['appointment_id'] ?? null);
        $linkedAppointment = null;
        if ($appointmentId !== null) {
            $linkedAppointment = $this->appointmentLookupService->findById((string) $appointmentId);
        }
        if ($appointmentId !== null && ($linkedAppointment === null || ($linkedAppointment['patient_id'] ?? null) !== $patientId)) {
            throw new AppointmentNotEligibleForAdmissionException(
                'Appointment is not valid for the selected patient.',
            );
        }

        if (array_key_exists('ward', $payload) || array_key_exists('bed', $payload)) {
            $normalizedPlacement = $this->admissionPlacementLookupService->validatePlacement(
                ward: $payload['ward'] ?? ($existing['ward'] ?? null),
                bed: $payload['bed'] ?? ($existing['bed'] ?? null),
                excludeAdmissionId: $id,
            );

            if (array_key_exists('ward', $payload)) {
                $payload['ward'] = $normalizedPlacement['ward'];
            }

            if (array_key_exists('bed', $payload)) {
                $payload['bed'] = $normalizedPlacement['bed'];
            }
        }

        $payload = $this->inheritFinancialCoverage($payload, $existing, $linkedAppointment);
        $payload = $this->normalizeFinancialCoverage($payload, $existing);

        $updated = $this->admissionRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                admissionId: $id,
                action: 'admission.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'patient_id',
            'appointment_id',
            'attending_clinician_user_id',
            'ward',
            'bed',
            'admitted_at',
            'discharged_at',
            'admission_reason',
            'notes',
            'financial_coverage_type',
            'billing_payer_contract_id',
            'coverage_reference',
            'coverage_notes',
            'status',
            'status_reason',
            'discharge_destination',
            'follow_up_plan',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>|null  $appointment
     * @return array<string, mixed>
     */
    private function inheritFinancialCoverage(array $payload, array $existing, ?array $appointment): array
    {
        if ($appointment === null) {
            return $payload;
        }

        $appointmentChanged = array_key_exists('appointment_id', $payload)
            && (($payload['appointment_id'] ?? null) !== ($existing['appointment_id'] ?? null));

        if (! $appointmentChanged) {
            return $payload;
        }

        foreach (['financial_coverage_type', 'billing_payer_contract_id', 'coverage_reference', 'coverage_notes'] as $field) {
            if (array_key_exists($field, $payload) && $this->normalizeNullableString($payload[$field] ?? null) !== null) {
                continue;
            }

            if ($this->normalizeNullableString($existing[$field] ?? null) !== null) {
                continue;
            }

            $payload[$field] = $appointment[$field] ?? null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function normalizeFinancialCoverage(array $payload, array $existing): array
    {
        $hasCoverageFieldChange = array_key_exists('financial_coverage_type', $payload)
            || array_key_exists('billing_payer_contract_id', $payload)
            || array_key_exists('coverage_reference', $payload)
            || array_key_exists('coverage_notes', $payload);

        if (! $hasCoverageFieldChange) {
            return $payload;
        }

        $payload['financial_coverage_type'] = FinancialCoverage::normalize(
            isset($payload['financial_coverage_type'])
                ? (string) $payload['financial_coverage_type']
                : (isset($existing['financial_coverage_type']) ? (string) $existing['financial_coverage_type'] : null),
        );

        if (array_key_exists('billing_payer_contract_id', $payload)) {
            $payload['billing_payer_contract_id'] = $this->normalizeNullableString($payload['billing_payer_contract_id'] ?? null);
        }

        if (array_key_exists('coverage_reference', $payload)) {
            $payload['coverage_reference'] = $this->normalizeNullableString($payload['coverage_reference'] ?? null);
        }

        if (array_key_exists('coverage_notes', $payload)) {
            $payload['coverage_notes'] = $this->normalizeNullableString($payload['coverage_notes'] ?? null);
        }

        return $payload;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
