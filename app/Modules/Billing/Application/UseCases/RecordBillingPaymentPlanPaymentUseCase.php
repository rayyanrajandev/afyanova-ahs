<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPaymentPlanRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Carbon\CarbonImmutable;
use RuntimeException;

class RecordBillingPaymentPlanPaymentUseCase
{
    public function __construct(
        private readonly BillingPaymentPlanRepositoryInterface $repository,
        private readonly RecordBillingInvoicePaymentUseCase $recordBillingInvoicePaymentUseCase,
        private readonly RecordCashPaymentUseCase $recordCashPaymentUseCase,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $billingPaymentPlanId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $plan = $this->repository->findById($billingPaymentPlanId);
        if ($plan === null) {
            return null;
        }

        if (in_array((string) ($plan['status'] ?? ''), ['completed', 'cancelled'], true)) {
            throw new RuntimeException('Payment cannot be recorded against a completed or cancelled payment plan.');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        $balanceAmount = round((float) ($plan['balance_amount'] ?? 0), 2);
        if ($balanceAmount <= 0) {
            throw new RuntimeException('Payment plan has no remaining balance.');
        }

        $paymentAmount = min($amount, $balanceAmount);
        $paymentAt = (string) ($payload['payment_at'] ?? now()->toDateTimeString());
        $paymentReference = $this->nullableString($payload['payment_reference'] ?? null);
        $note = $this->nullableString($payload['note'] ?? null) ?? 'Payment posted from payment plan workspace.';
        $paymentMethod = (string) ($payload['payment_method'] ?? 'cash');
        $payerType = (string) ($payload['payer_type'] ?? 'self_pay');

        $sourceBillingInvoicePaymentId = null;
        $sourceCashBillingPaymentId = null;
        if (! empty($plan['billing_invoice_id'])) {
            $result = $this->recordBillingInvoicePaymentUseCase->execute(
                billingInvoiceId: (string) $plan['billing_invoice_id'],
                amount: $paymentAmount,
                payerType: $payerType,
                paymentMethod: $paymentMethod,
                paymentReference: $paymentReference,
                note: $note,
                paymentAt: $paymentAt,
                actorId: $actorId,
            );
            if ($result === null) {
                throw new RuntimeException('Unable to post the payment to the linked invoice.');
            }
            $sourceBillingInvoicePaymentId = (string) ($result['payment']['id'] ?? '');
        } elseif (! empty($plan['cash_billing_account_id'])) {
            $result = $this->recordCashPaymentUseCase->execute([
                'cash_billing_account_id' => (string) $plan['cash_billing_account_id'],
                'amount_paid' => $paymentAmount,
                'currency_code' => $plan['currency_code'] ?? 'TZS',
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference,
                'paid_at' => $paymentAt,
                'notes' => $note,
                'confirmed_by_user_id' => $actorId,
            ]);
            $sourceCashBillingPaymentId = (string) ($result['id'] ?? '');
        } else {
            throw new RuntimeException('Payment plan is not linked to a supported billing source.');
        }

        $remainingAllocation = $paymentAmount;
        $installments = $this->repository->installments($billingPaymentPlanId);
        foreach ($installments as $installment) {
            if ($remainingAllocation <= 0) {
                break;
            }

            $outstanding = round((float) ($installment['outstanding_amount'] ?? 0), 2);
            if ($outstanding <= 0) {
                continue;
            }

            $allocation = min($remainingAllocation, $outstanding);
            $updatedPaidAmount = round((float) ($installment['paid_amount'] ?? 0) + $allocation, 2);
            $updatedOutstanding = round(max($outstanding - $allocation, 0), 2);

            $this->repository->updateInstallment((string) $installment['id'], [
                'paid_amount' => $updatedPaidAmount,
                'outstanding_amount' => $updatedOutstanding,
                'paid_at' => $updatedOutstanding <= 0 ? $paymentAt : ($installment['paid_at'] ?? null),
                'status' => $this->installmentStatus($updatedOutstanding, (string) $installment['due_date']),
                'source_billing_invoice_payment_id' => $sourceBillingInvoicePaymentId ?: null,
                'source_cash_billing_payment_id' => $sourceCashBillingPaymentId ?: null,
            ]);

            $remainingAllocation = round($remainingAllocation - $allocation, 2);
        }

        $refreshedInstallments = $this->repository->installments($billingPaymentPlanId);
        $nextDueDate = null;
        foreach ($refreshedInstallments as $installment) {
            if ((float) ($installment['outstanding_amount'] ?? 0) > 0) {
                $nextDueDate = $installment['due_date'] ?? null;
                break;
            }
        }

        $updatedPaidTotal = round((float) ($plan['paid_amount'] ?? 0) + $paymentAmount, 2);
        $updatedBalance = round(max((float) ($plan['total_amount'] ?? 0) - $updatedPaidTotal, 0), 2);
        $updatedStatus = $updatedBalance <= 0 ? 'completed' : ($updatedPaidTotal > 0 ? 'partially_paid' : 'active');
        if ($updatedBalance > 0 && $this->hasOverdueInstallment($refreshedInstallments)) {
            $updatedStatus = 'defaulted';
        }

        $updatedPlan = $this->repository->update($billingPaymentPlanId, [
            'paid_amount' => $updatedPaidTotal,
            'balance_amount' => $updatedBalance,
            'last_payment_at' => $paymentAt,
            'next_due_date' => $nextDueDate,
            'status' => $updatedStatus,
        ]);

        if ($updatedPlan === null) {
            return null;
        }

        $updatedPlan['installments'] = $refreshedInstallments;

        return $updatedPlan;
    }

    private function installmentStatus(float $outstandingAmount, string $dueDate): string
    {
        if ($outstandingAmount <= 0) {
            return 'paid';
        }

        $due = CarbonImmutable::parse($dueDate)->endOfDay();
        if ($due->isPast()) {
            return 'overdue';
        }

        return $outstandingAmount < 0.01 ? 'paid' : 'partially_paid';
    }

    private function hasOverdueInstallment(array $installments): bool
    {
        foreach ($installments as $installment) {
            if ((string) ($installment['status'] ?? '') === 'overdue') {
                return true;
            }
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
