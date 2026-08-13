<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\CashBillingAccountRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\CashBillingPaymentRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\CashBillingPaymentModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RefundCashBillingPaymentUseCase
{
    public function __construct(
        private readonly CashBillingAccountRepositoryInterface $cashBillingAccountRepository,
        private readonly CashBillingPaymentRepositoryInterface $cashBillingPaymentRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload): array {
            $accountId = (string) $payload['cash_billing_account_id'];
            $paymentId = (string) $payload['payment_id'];
            $refundAmount = (float) $payload['refund_amount'];

            $account = $this->cashBillingAccountRepository->findById($accountId);

            if ($account === null) {
                throw new RuntimeException('Cash billing account not found.');
            }

            if ($account['status'] !== 'active') {
                throw new RuntimeException('Only active cash billing accounts can have refunds processed.');
            }

            $payment = $this->cashBillingPaymentRepository->findById($paymentId);

            if ($payment === null) {
                throw new RuntimeException('Payment not found.');
            }

            if ($payment['cash_billing_account_id'] !== $accountId) {
                throw new RuntimeException('Payment does not belong to this account.');
            }

            $originalAmount = (float) ($payment['amount_paid'] ?? 0);
            $alreadyRefunded = (float) ($payment['refunded_amount'] ?? 0);
            $availableForRefund = $originalAmount - $alreadyRefunded;

            if ($refundAmount <= 0) {
                throw new RuntimeException('Refund amount must be greater than 0.');
            }

            if ($refundAmount > $availableForRefund) {
                throw new RuntimeException(
                    'Refund amount exceeds available balance for this payment. '
                    .'Original: '.$originalAmount.', already refunded: '.$alreadyRefunded
                );
            }

            $newRefundedAmount = $alreadyRefunded + $refundAmount;

            CashBillingPaymentModel::where('id', $paymentId)->update([
                'refunded_amount' => $newRefundedAmount,
                'refunded_at' => now(),
                'refunded_by_user_id' => $payload['confirmed_by_user_id'] ?? null,
                'refund_reason' => $payload['refund_reason'] ?? null,
            ]);

            $this->cashBillingAccountRepository->update($accountId, [
                'total_paid' => max(0, (float) ($account['total_paid'] ?? 0) - $refundAmount),
                'account_balance' => round(
                    (float) ($account['account_balance'] ?? 0) + $refundAmount, 2
                ),
            ]);

            $updatedPayment = $this->cashBillingPaymentRepository->findById($paymentId);

            return [
                'payment' => $updatedPayment ?? $payment,
                'account' => $this->cashBillingAccountRepository->findById($accountId) ?? $account,
            ];
        });
    }
}
