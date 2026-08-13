<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingPayerContractPriceOverrideStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateBillingPayerContractPriceOverrideStatusUseCase
{
    public function __construct(
        private readonly BillingPayerContractRepositoryInterface $contractRepository,
        private readonly BillingPayerContractPriceOverrideRepositoryInterface $priceOverrideRepository,
        private readonly BillingPayerContractPriceOverrideAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $billingPayerContractId, string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $contract = $this->contractRepository->findById($billingPayerContractId);
        if (! $contract) {
            return null;
        }

        $existing = $this->priceOverrideRepository->findById($id);
        if (! $existing || ($existing['billing_payer_contract_id'] ?? null) !== $billingPayerContractId) {
            return null;
        }

        $updated = $this->priceOverrideRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
        ]);
        if (! $updated) {
            return null;
        }

        $reasonRequired = in_array($status, [
            BillingPayerContractPriceOverrideStatus::INACTIVE->value,
            BillingPayerContractPriceOverrideStatus::RETIRED->value,
        ], true);

        $this->auditLogRepository->write(
            billingPayerContractPriceOverrideId: $id,
            action: 'billing-payer-contract-price-override.status.updated',
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
                'billing_payer_contract_id' => $billingPayerContractId,
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
