<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderAuditLogRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class DiscardPharmacyOrderDraftUseCase
{
    public function __construct(
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly PharmacyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): bool
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->pharmacyOrderRepository->findById($id);
        if (! $existing) {
            return false;
        }

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'pharmacy order');

        $this->auditLogRepository->write(
            pharmacyOrderId: $id,
            action: 'pharmacy-order.draft.discarded',
            actorId: $actorId,
            metadata: [
                'orderNumber' => $existing['order_number'] ?? null,
                'patientId' => $existing['patient_id'] ?? null,
                'medicationCode' => $existing['medication_code'] ?? null,
                'medicationName' => $existing['medication_name'] ?? null,
            ],
        );

        return $this->pharmacyOrderRepository->delete($id);
    }
}
