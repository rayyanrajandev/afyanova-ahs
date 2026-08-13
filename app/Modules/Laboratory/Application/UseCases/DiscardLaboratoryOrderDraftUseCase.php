<?php

namespace App\Modules\Laboratory\Application\UseCases;

use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderAuditLogRepositoryInterface;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class DiscardLaboratoryOrderDraftUseCase
{
    public function __construct(
        private readonly LaboratoryOrderRepositoryInterface $laboratoryOrderRepository,
        private readonly LaboratoryOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): bool
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->laboratoryOrderRepository->findById($id);
        if (! $existing) {
            return false;
        }

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'laboratory order');

        $this->auditLogRepository->write(
            laboratoryOrderId: $id,
            action: 'laboratory-order.draft.discarded',
            actorId: $actorId,
            metadata: [
                'orderNumber' => $existing['order_number'] ?? null,
                'patientId' => $existing['patient_id'] ?? null,
                'testCode' => $existing['test_code'] ?? null,
                'testName' => $existing['test_name'] ?? null,
            ],
        );

        return $this->laboratoryOrderRepository->delete($id);
    }
}
