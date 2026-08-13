<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\CashBillingAccountRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\CashBillingChargeRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\CashBillingChargeModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class RecordCashChargeUseCase
{
    public function __construct(
        private readonly CashBillingAccountRepositoryInterface $cashBillingAccountRepository,
        private readonly CashBillingChargeRepositoryInterface $chargeRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * Record a charge against a cash billing account
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function execute(array $payload): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $accountId = (string) $payload['cash_billing_account_id'];

        // Verify account exists and is active
        $account = $this->cashBillingAccountRepository->findById($accountId);
        if ($account === null || $account['status'] !== 'active') {
            throw new \RuntimeException('Cash billing account not found or inactive.');
        }

        // Calculate charge amount
        $quantity = (int) $payload['quantity'];
        $unitPrice = (float) $payload['unit_price'];
        $chargeAmount = $quantity * $unitPrice;

        // Record charge
        $charge = CashBillingChargeModel::create([
            'cash_billing_account_id' => $accountId,
            'service_id' => $payload['service_id'] ?? null,
            'service_name' => $payload['service_name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'charge_amount' => $chargeAmount,
            'recorded_by_user_id' => $payload['recorded_by_user_id'],
            'charge_date' => $payload['charge_date'] ?? now(),
            'reference_id' => $payload['reference_id'] ?? null,
            'reference_type' => $payload['reference_type'] ?? null,
            'description' => $payload['description'] ?? null,
        ]);

        // Update account balance
        $this->cashBillingAccountRepository->update($accountId, [
            'account_balance' => $account['account_balance'] + $chargeAmount,
            'total_charged' => $account['total_charged'] + $chargeAmount,
        ]);

        return $charge->toArray();
    }
}
