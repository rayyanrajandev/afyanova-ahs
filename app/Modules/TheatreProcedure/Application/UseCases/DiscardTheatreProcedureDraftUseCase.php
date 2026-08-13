<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class DiscardTheatreProcedureDraftUseCase
{
    public function __construct(
        private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository,
        private readonly TheatreProcedureAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): bool
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->theatreProcedureRepository->findById($id);
        if (! $existing) {
            return false;
        }

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'theatre procedure');

        $this->auditLogRepository->write(
            theatreProcedureId: $id,
            action: 'theatre-procedure.draft.discarded',
            actorId: $actorId,
            metadata: [
                'procedureNumber' => $existing['procedure_number'] ?? null,
                'patientId' => $existing['patient_id'] ?? null,
                'procedureType' => $existing['procedure_type'] ?? null,
                'procedureName' => $existing['procedure_name'] ?? null,
            ],
        );

        return $this->theatreProcedureRepository->delete($id);
    }
}
