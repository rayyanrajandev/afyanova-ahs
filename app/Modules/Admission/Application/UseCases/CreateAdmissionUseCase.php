<?php

namespace App\Modules\Admission\Application\UseCases;

use App\Modules\Admission\Application\Exceptions\AppointmentNotEligibleForAdmissionException;
use App\Modules\Admission\Application\Exceptions\PatientNotEligibleForAdmissionException;
use App\Modules\Admission\Domain\Repositories\AdmissionAuditLogRepositoryInterface;
use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\Admission\Domain\Services\AdmissionPlacementLookupServiceInterface;
use App\Modules\Admission\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Admission\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Admission\Domain\ValueObjects\AdmissionStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\FinancialCoverage;
use Illuminate\Support\Str;
use RuntimeException;

class CreateAdmissionUseCase
{
    public function __construct(
        private readonly AdmissionAuditLogRepositoryInterface $auditLogRepository,
        private readonly AdmissionRepositoryInterface $admissionRepository,
        private readonly AdmissionPlacementLookupServiceInterface $admissionPlacementLookupService,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $patientId = (string) $payload['patient_id'];
        $eligibility = $this->patientLookupService->findAdmissionEligibilityById($patientId);
        if ($eligibility === null || ($eligibility['status'] ?? null) !== 'active') {
            throw new PatientNotEligibleForAdmissionException(
                'Admission can only be created for an active patient.',
            );
        }

        $appointmentId = $payload['appointment_id'] ?? null;
        $linkedAppointment = null;
        if ($appointmentId !== null) {
            $linkedAppointment = $this->appointmentLookupService->findById((string) $appointmentId);
        }
        if ($appointmentId !== null && ($linkedAppointment === null || ($linkedAppointment['patient_id'] ?? null) !== $patientId)) {
            throw new AppointmentNotEligibleForAdmissionException(
                'Appointment is not valid for the selected patient.',
            );
        }

        $bedResourceId = isset($payload['bed_resource_id']) ? trim((string) $payload['bed_resource_id']) : '';
        if ($bedResourceId !== '') {
            $normalizedPlacement = $this->admissionPlacementLookupService->validatePlacementByResource(
                bedResourceId: $bedResourceId,
                patientGender: $eligibility['gender'] ?? null,
            );
            $payload['bed_resource_id'] = $normalizedPlacement['bed_resource_id'];
            $payload['ward'] = $normalizedPlacement['ward'];
            $payload['bed'] = $normalizedPlacement['bed'];
        } else {
            $normalizedPlacement = $this->admissionPlacementLookupService->validatePlacement(
                ward: $payload['ward'] ?? null,
                bed: $payload['bed'] ?? null,
            );
            $payload['ward'] = $normalizedPlacement['ward'];
            $payload['bed'] = $normalizedPlacement['bed'];
        }

        $payload['status'] = AdmissionStatus::ADMITTED->value;
        $payload['admission_number'] = $this->generateAdmissionNumber();
        $payload['tenant_id'] = $this->platformScopeContext->tenantId();
        $payload['facility_id'] = $this->platformScopeContext->facilityId();
        $payload = $this->inheritFinancialCoverage($payload, $linkedAppointment);
        $payload = $this->normalizeFinancialCoverage($payload);

        $createdAdmission = $this->admissionRepository->create($payload);

        $this->auditLogRepository->write(
            admissionId: $createdAdmission['id'],
            action: 'admission.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($createdAdmission),
            ],
        );

        return $createdAdmission;
    }

    private function generateAdmissionNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'ADM'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->admissionRepository->existsByAdmissionNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique admission number.');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $admission): array
    {
        $tracked = [
            'admission_number',
            'tenant_id',
            'facility_id',
            'patient_id',
            'appointment_id',
            'attending_clinician_user_id',
            'ward',
            'bed',
            'bed_resource_id',
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

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $admission[$field] ?? null;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $appointment
     * @return array<string, mixed>
     */
    private function inheritFinancialCoverage(array $payload, ?array $appointment): array
    {
        if ($appointment === null) {
            return $payload;
        }

        foreach (['financial_coverage_type', 'billing_payer_contract_id', 'coverage_reference', 'coverage_notes'] as $field) {
            if (array_key_exists($field, $payload) && $this->normalizeNullableString($payload[$field] ?? null) !== null) {
                continue;
            }

            if (array_key_exists($field, $appointment)) {
                $payload[$field] = $appointment[$field];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeFinancialCoverage(array $payload): array
    {
        $payload['financial_coverage_type'] = FinancialCoverage::normalize(
            isset($payload['financial_coverage_type']) ? (string) $payload['financial_coverage_type'] : null,
        );
        $payload['billing_payer_contract_id'] = $this->normalizeNullableString($payload['billing_payer_contract_id'] ?? null);
        $payload['coverage_reference'] = $this->normalizeNullableString($payload['coverage_reference'] ?? null);
        $payload['coverage_notes'] = $this->normalizeNullableString($payload['coverage_notes'] ?? null);

        return $payload;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
