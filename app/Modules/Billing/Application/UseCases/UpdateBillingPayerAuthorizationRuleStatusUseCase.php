<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingPayerAuthorizationRuleStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateBillingPayerAuthorizationRuleStatusUseCase
{
    public function __construct(
        private readonly BillingPayerAuthorizationRuleRepositoryInterface $ruleRepository,
        private readonly BillingPayerAuthorizationRuleAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $billingPayerContractId,
        string $id,
        string $status,
        ?string $reason,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->ruleRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (($existing['billing_payer_contract_id'] ?? null) !== $billingPayerContractId) {
            return null;
        }

        $updated = $this->ruleRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
        ]);
        if (! $updated) {
            return null;
        }

        $reasonRequired = in_array($status, [
            BillingPayerAuthorizationRuleStatus::INACTIVE->value,
            BillingPayerAuthorizationRuleStatus::RETIRED->value,
        ], true);

        $this->auditLogRepository->write(
            billingPayerAuthorizationRuleId: $id,
            action: 'billing-payer-authorization-rule.status.updated',
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
