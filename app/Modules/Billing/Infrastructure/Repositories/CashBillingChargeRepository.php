<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\CashBillingChargeRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\CashBillingChargeModel;

class CashBillingChargeRepository implements CashBillingChargeRepositoryInterface
{
    /**
     * Find a charge by ID
     */
    public function findById(string $id): ?array
    {
        $charge = CashBillingChargeModel::find($id);

        return $charge?->toArray();
    }

    /**
     * Find all charges for an account
     */
    public function findByAccountId(string $accountId): array
    {
        return CashBillingChargeModel::where('cash_billing_account_id', $accountId)
            ->orderBy('charge_date', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Create a new charge
     */
    public function create(array $data): array
    {
        $charge = CashBillingChargeModel::create($data);

        return $charge->toArray();
    }
}
