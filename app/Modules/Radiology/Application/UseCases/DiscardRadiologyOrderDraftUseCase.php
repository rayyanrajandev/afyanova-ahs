<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderAuditLogRepositoryInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderRepositoryInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class DiscardRadiologyOrderDraftUseCase
{
    public function __construct(
        private readonly RadiologyOrderRepositoryInterface $radiologyOrderRepository,
        private readonly RadiologyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): bool
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->radiologyOrderRepository->findById($id);
        if (! $existing) {
            return false;
        }

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'radiology order');

        $this->auditLogRepository->write(
            radiologyOrderId: $id,
            action: 'radiology-order.draft.discarded',
            actorId: $actorId,
            metadata: [
                'orderNumber' => $existing['order_number'] ?? null,
                'patientId' => $existing['patient_id'] ?? null,
                'procedureCode' => $existing['procedure_code'] ?? null,
                'studyDescription' => $existing['study_description'] ?? null,
            ],
        );

        return $this->radiologyOrderRepository->delete($id);
    }
}
