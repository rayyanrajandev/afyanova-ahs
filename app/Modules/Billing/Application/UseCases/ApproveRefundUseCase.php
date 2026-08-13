<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingRefundAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingRefundRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class ApproveRefundUseCase
{
    public function __construct(
        private readonly BillingRefundRepositoryInterface $refundRepository,
        private readonly BillingRefundAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * Approve a refund request
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
        if ($refund['refund_status'] !== 'pending') {
            throw new \RuntimeException(
                'Refund must be in pending status to approve. Current status: ' . $refund['refund_status']
            );
        }

        // Update refund
        $updated = $this->refundRepository->update($refundId, [
            'approved_by_user_id' => $actorId,
            'approved_at' => now(),
            'refund_status' => 'approved',
        ]);

        // Log audit
        $this->auditLogRepository->create([
            'billing_refund_id' => $refundId,
            'action' => 'approved',
            'actor_id' => $actorId,
            'actor_name' => $payload['actor_name'] ?? 'System',
            'notes' => $payload['notes'] ?? null,
        ]);

        return $updated;
    }
}
