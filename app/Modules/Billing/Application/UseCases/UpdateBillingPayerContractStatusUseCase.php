<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingPayerContractStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateBillingPayerContractStatusUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $repository,
        private readonly BillingPayerContractAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->repository->findById($id);
        if (! $existing) {
            return null;
        }

        $updated = $this->repository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
        ]);

        if (! $updated) {
            return null;
        }

        $reasonRequired = in_array($status, [
            BillingPayerContractStatus::INACTIVE->value,
            BillingPayerContractStatus::RETIRED->value,
        ], true);

        $this->auditLogRepository->write(
            billingPayerContractId: $id,
            action: 'billing-payer-contract.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
            ],
        );

        return $updated;
    }
}
