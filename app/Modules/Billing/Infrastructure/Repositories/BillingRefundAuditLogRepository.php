<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingRefundAuditLogRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingRefundAuditLogModel;

class BillingRefundAuditLogRepository implements BillingRefundAuditLogRepositoryInterface
{
    public function create(array $data): array
    {
        $log = BillingRefundAuditLogModel::create($data);

        return $log->toArray();
    }

    public function findByRefundId(string $refundId): array
    {
        return BillingRefundAuditLogModel::where('billing_refund_id', $refundId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
    }
}
