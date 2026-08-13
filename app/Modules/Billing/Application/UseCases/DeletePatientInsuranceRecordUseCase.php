<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\PatientInsuranceRepositoryInterface;
use App\Modules\Billing\Infrastructure\Repositories\PatientInsuranceAuditEventRepository;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class DeletePatientInsuranceRecordUseCase
{
    public function __construct(
        private readonly PatientInsuranceRepositoryInterface $repository,
        private readonly PatientInsuranceAuditEventRepository $auditEventRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $patientId, string $recordId, ?int $actorId = null): bool
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->repository->findById($recordId);
        if ($existing === null || ($existing['patient_id'] ?? null) !== $patientId) {
            return false;
        }

        $this->auditEventRepository->write(
            patientInsuranceRecordId: $recordId,
            patientId: $patientId,
            action: 'patient-insurance.deleted',
            actorId: $actorId,
            changes: ['before' => $existing],
        );

        return $this->repository->delete($recordId);
    }
}
