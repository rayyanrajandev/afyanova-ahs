<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingRefundAuditLogRepositoryInterface
{
    /**
     * Create an audit log entry
     */
    public function create(array $data): array;

    /**
     * Get all audit logs for a refund
     */
    public function findByRefundId(string $refundId): array;
}
