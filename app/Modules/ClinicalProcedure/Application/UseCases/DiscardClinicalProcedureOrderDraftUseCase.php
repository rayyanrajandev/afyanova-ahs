<?php

namespace App\Modules\ClinicalProcedure\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderAuditLogRepositoryInterface;
use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderRepositoryInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class DiscardClinicalProcedureOrderDraftUseCase
{
    public function __construct(
        private readonly ClinicalProcedureOrderRepositoryInterface $clinicalProcedureOrderRepository,
        private readonly ClinicalProcedureOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): bool
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->clinicalProcedureOrderRepository->findById($id);
        if (! $existing) {
            return false;
        }

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'clinical procedure order');

        $this->auditLogRepository->write(
            clinicalProcedureOrderId: $id,
            action: 'clinical-procedure-order.draft.discarded',
            actorId: $actorId,
            metadata: [
                'orderNumber' => $existing['order_number'] ?? null,
                'patientId' => $existing['patient_id'] ?? null,
                'procedureCode' => $existing['procedure_code'] ?? null,
                'procedureDescription' => $existing['procedure_description'] ?? null,
            ],
        );

        return $this->clinicalProcedureOrderRepository->delete($id);
    }
}
