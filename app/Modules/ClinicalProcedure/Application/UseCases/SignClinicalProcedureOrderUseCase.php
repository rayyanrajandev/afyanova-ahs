<?php

namespace App\Modules\ClinicalProcedure\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderAuditLogRepositoryInterface;
use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderRepositoryInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Validation\ValidationException;

class SignClinicalProcedureOrderUseCase
{
    public function __construct(
        private readonly ClinicalProcedureOrderRepositoryInterface $clinicalProcedureOrderRepository,
        private readonly ClinicalProcedureOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->clinicalProcedureOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (! ClinicalOrderLifecycle::isDraft($existing)) {
            throw ValidationException::withMessages([
                'order' => ['This clinical procedure order is already signed.'],
            ]);
        }

        $payload = [];
        ClinicalOrderLifecycle::applyActiveEntryState($payload, $actorId);

        $updated = $this->clinicalProcedureOrderRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            clinicalProcedureOrderId: $id,
            action: 'clinical-procedure-order.signed',
            actorId: $actorId,
            changes: [
                'entry_state' => [
                    'before' => $existing['entry_state'] ?? null,
                    'after' => $updated['entry_state'] ?? null,
                ],
                'signed_at' => [
                    'before' => $existing['signed_at'] ?? null,
                    'after' => $updated['signed_at'] ?? null,
                ],
                'signed_by_user_id' => [
                    'before' => $existing['signed_by_user_id'] ?? null,
                    'after' => $updated['signed_by_user_id'] ?? null,
                ],
                'lifecycle_locked_at' => [
                    'before' => $existing['lifecycle_locked_at'] ?? null,
                    'after' => $updated['lifecycle_locked_at'] ?? null,
                ],
            ],
        );

        return $updated;
    }
}
