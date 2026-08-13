<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderAuditLogRepositoryInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderRepositoryInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Validation\ValidationException;

class SignRadiologyOrderUseCase
{
    public function __construct(
        private readonly RadiologyOrderRepositoryInterface $radiologyOrderRepository,
        private readonly RadiologyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->radiologyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (! ClinicalOrderLifecycle::isDraft($existing)) {
            throw ValidationException::withMessages([
                'order' => ['This radiology order is already signed.'],
            ]);
        }

        $payload = [];
        ClinicalOrderLifecycle::applyActiveEntryState($payload, $actorId);

        $updated = $this->radiologyOrderRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            radiologyOrderId: $id,
            action: 'radiology-order.signed',
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
