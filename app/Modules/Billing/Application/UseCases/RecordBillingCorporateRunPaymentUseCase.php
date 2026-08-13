<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingCorporateAccountRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use RuntimeException;

class RecordBillingCorporateRunPaymentUseCase
{
    public function __construct(
        private readonly BillingCorporateAccountRepositoryInterface $repository,
        private readonly RecordBillingInvoicePaymentUseCase $recordBillingInvoicePaymentUseCase,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $runId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $run = $this->repository->findRunById($runId);
        if ($run === null) {
            return null;
        }

        if (in_array((string) ($run['status'] ?? ''), ['paid', 'closed', 'cancelled'], true)) {
            throw new RuntimeException('Corporate run does not accept new payments in its current status.');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Corporate payment amount must be greater than zero.');
        }

        $remainingBalance = round((float) ($run['balance_amount'] ?? 0), 2);
        if ($remainingBalance <= 0) {
            throw new RuntimeException('Corporate run is already fully settled.');
        }

        $paymentAmount = min($amount, $remainingBalance);
        $paymentMethod = (string) ($payload['payment_method'] ?? 'bank_transfer');
        $paymentReference = trim((string) ($payload['payment_reference'] ?? '')) ?: null;
        $paymentAt = (string) ($payload['payment_at'] ?? now()->toDateTimeString());
        $note = trim((string) ($payload['note'] ?? '')) ?: 'Corporate settlement payment recorded.';

        $runInvoices = $this->repository->runInvoices($runId);
        $allocations = [];
        $remainingAllocation = $paymentAmount;
        foreach ($runInvoices as $runInvoice) {
            if ($remainingAllocation <= 0) {
                break;
            }

            $outstanding = round((float) ($runInvoice['outstanding_amount'] ?? 0), 2);
            if ($outstanding <= 0) {
                continue;
            }

            $allocation = min($remainingAllocation, $outstanding);
            $result = $this->recordBillingInvoicePaymentUseCase->execute(
                billingInvoiceId: (string) $runInvoice['billing_invoice_id'],
                amount: $allocation,
                payerType: 'employer',
                paymentMethod: $paymentMethod,
                paymentReference: $paymentReference,
                note: $note,
                paymentAt: $paymentAt,
                actorId: $actorId,
            );
            if ($result === null) {
                throw new RuntimeException('Unable to post payment to one of the source invoices.');
            }

            $updatedPaid = round((float) ($runInvoice['paid_amount'] ?? 0) + $allocation, 2);
            $updatedOutstanding = round(max($outstanding - $allocation, 0), 2);
            $this->repository->updateRunInvoice((string) $runInvoice['id'], [
                'paid_amount' => $updatedPaid,
                'outstanding_amount' => $updatedOutstanding,
                'status' => $updatedOutstanding <= 0 ? 'paid' : 'partially_paid',
            ]);

            $allocations[] = [
                'billingInvoiceId' => $runInvoice['billing_invoice_id'],
                'invoiceNumber' => $runInvoice['invoice_number'],
                'amount' => $allocation,
                'billingInvoicePaymentId' => $result['payment']['id'] ?? null,
            ];

            $remainingAllocation = round($remainingAllocation - $allocation, 2);
        }

        $updatedPaidAmount = round((float) ($run['paid_amount'] ?? 0) + $paymentAmount, 2);
        $updatedBalanceAmount = round(max((float) ($run['total_amount'] ?? 0) - $updatedPaidAmount, 0), 2);
        $updatedStatus = $updatedBalanceAmount <= 0 ? 'paid' : 'partially_paid';

        $this->repository->createRunPayment([
            'billing_corporate_invoice_run_id' => $runId,
            'amount' => $paymentAmount,
            'currency_code' => $run['currency_code'] ?? 'TZS',
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentReference,
            'paid_at' => $paymentAt,
            'recorded_by_user_id' => $actorId,
            'note' => $note,
            'allocations' => $allocations,
        ]);

        $updatedRun = $this->repository->updateRun($runId, [
            'paid_amount' => $updatedPaidAmount,
            'balance_amount' => $updatedBalanceAmount,
            'last_payment_at' => $paymentAt,
            'status' => $updatedStatus,
        ]);

        if ($updatedRun === null) {
            return null;
        }

        $updatedRun['invoices'] = $this->repository->runInvoices($runId);
        $updatedRun['payments'] = $this->repository->runPayments($runId);

        return $updatedRun;
    }
}
