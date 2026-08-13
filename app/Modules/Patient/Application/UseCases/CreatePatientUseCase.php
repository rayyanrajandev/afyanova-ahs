<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Application\Services\PatientDuplicateDetectionService;
use App\Modules\Patient\Application\Services\PatientMrnGenerator;
use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Patient\Domain\ValueObjects\PatientPhoneNumber;
use App\Modules\Patient\Domain\ValueObjects\PatientStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreatePatientUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly PatientDuplicateDetectionService $duplicateDetectionService,
        private readonly PatientMrnGenerator $patientMrnGenerator,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        // Duplicate check runs before write: hard identifiers block, demographics warn.
        $warnings = $this->duplicateDetectionService->evaluate($payload);

        $tenantId = $this->platformScopeContext->tenantId();
        $payload['status'] = PatientStatus::ACTIVE->value;
        $payload['patient_number'] = $this->patientMrnGenerator->nextForTenant($tenantId);
        $payload['tenant_id'] = $tenantId;
        $payload['phone_normalized'] = PatientPhoneNumber::normalize($payload['phone'] ?? null) ?: null;

        $createdPatient = $this->patientRepository->create($payload);

        $this->auditLogRepository->write(
            patientId: $createdPatient['id'],
            action: 'patient.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractIdentity($createdPatient),
            ],
        );

        return [
            'patient' => $createdPatient,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractIdentity(array $patient): array
    {
        return [
            'first_name' => $patient['first_name'] ?? null,
            'last_name' => $patient['last_name'] ?? null,
            'date_of_birth' => $patient['date_of_birth'] ?? null,
            'phone' => $patient['phone'] ?? null,
            'national_id' => $patient['national_id'] ?? null,
        ];
    }
}
