<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingDiscountRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingDiscountModel;

class BillingDiscountRepository implements BillingDiscountRepositoryInterface
{
    public function findById(string $id): ?array
    {
        $discount = BillingDiscountModel::find($id);

        return $discount?->toArray();
    }

    public function findByInvoiceId(string $invoiceId): ?array
    {
        $discount = BillingDiscountModel::where('billing_invoice_id', $invoiceId)
            ->latest('applied_at')
            ->first();

        return $discount?->toArray();
    }

    public function create(array $data): array
    {
        $discount = BillingDiscountModel::create($data);

        return $discount->toArray();
    }
}
