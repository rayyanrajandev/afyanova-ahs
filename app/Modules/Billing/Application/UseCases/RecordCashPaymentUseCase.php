<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\CashBillingAccountRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\CashBillingPaymentModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class RecordCashPaymentUseCase
{
    public function __construct(
        private readonly CashBillingAccountRepositoryInterface $cashBillingAccountRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * Record a payment against a cash billing account
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function execute(array $payload): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $accountId = (string) $payload['cash_billing_account_id'];
        $amountPaid = (float) $payload['amount_paid'];

        // Verify account exists and is active
        $account = $this->cashBillingAccountRepository->findById($accountId);
        if ($account === null || $account['status'] !== 'active') {
            throw new \RuntimeException('Cash billing account not found or inactive.');
        }

        // Validate payment amount
        if ($amountPaid <= 0) {
            throw new \RuntimeException('Payment amount must be greater than 0.');
        }

        // Record payment
        $payment = CashBillingPaymentModel::create([
            'cash_billing_account_id' => $accountId,
            'amount_paid' => $amountPaid,
            'currency_code' => $payload['currency_code'] ?? $account['currency_code'],
            'payment_method' => $payload['payment_method'] ?? 'cash',
            'payment_reference' => $payload['payment_reference'] ?? null,
            'mobile_money_provider' => $payload['mobile_money_provider'] ?? null,
            'mobile_money_transaction_id' => $payload['mobile_money_transaction_id'] ?? null,
            'card_last_four' => $payload['card_last_four'] ?? null,
            'check_number' => $payload['check_number'] ?? null,
            'paid_at' => $payload['paid_at'] ?? now(),
            'confirmed_by_user_id' => $payload['confirmed_by_user_id'],
            'receipt_number' => $this->generateReceiptNumber(),
            'notes' => $payload['notes'] ?? null,
        ]);

        // Update account balance
        $newBalance = max(0, $account['account_balance'] - $amountPaid);
        $this->cashBillingAccountRepository->update($accountId, [
            'account_balance' => $newBalance,
            'total_paid' => $account['total_paid'] + $amountPaid,
            'status' => $newBalance === 0 ? 'settled' : 'active',
        ]);

        return array_merge($payment->toArray(), [
            'remaining_balance' => $newBalance,
        ]);
    }

    private function generateReceiptNumber(): string
    {
        return 'RCP-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(8));
    }
}
