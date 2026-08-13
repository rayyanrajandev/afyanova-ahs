<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Support\BillingFinancePostingService;
use App\Modules\Billing\Domain\Repositories\BillingRefundAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingRefundRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class ProcessRefundUseCase
{
    public function __construct(
        private readonly BillingRefundRepositoryInterface $refundRepository,
        private readonly BillingRefundAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly BillingFinancePostingService $billingFinancePostingService,
    ) {}

    /**
     * Process an approved refund (actually send money back)
     *
     * @param array<string, mixed> $payload
     * @param int|null $actorId
     *
     * @return array<string, mixed>
     */
    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $refundId = (string) $payload['refund_id'];

        // Get refund
        $refund = $this->refundRepository->findById($refundId);
        if ($refund === null) {
            throw new \RuntimeException('Refund not found: ' . $refundId);
        }

        // Validate status
        if ($refund['refund_status'] !== 'approved') {
            throw new \RuntimeException(
                'Refund must be approved before processing. Current status: ' . $refund['refund_status']
            );
        }

        // Update refund method if provided
        $updateData = [
            'processed_by_user_id' => $actorId,
            'processed_at' => now(),
            'refund_status' => 'processed',
        ];

        if (isset($payload['mobile_money_reference'])) {
            $updateData['mobile_money_reference'] = $payload['mobile_money_reference'];
        }
        if (isset($payload['check_number'])) {
            $updateData['check_number'] = $payload['check_number'];
        }
        if (isset($payload['card_reference'])) {
            $updateData['card_reference'] = $payload['card_reference'];
        }

        // Update refund
        $updated = $this->refundRepository->update($refundId, $updateData);

        // Log audit
        $this->auditLogRepository->create([
            'billing_refund_id' => $refundId,
            'action' => 'processed',
            'actor_id' => $actorId,
            'actor_name' => $payload['actor_name'] ?? 'System',
            'notes' => $payload['notes'] ?? null,
        ]);

        $postedRefund = $this->refundRepository->findById($refundId) ?? $updated;

        $this->billingFinancePostingService->recordRefundPosting($postedRefund, $actorId);

        return $updated;
    }
}
