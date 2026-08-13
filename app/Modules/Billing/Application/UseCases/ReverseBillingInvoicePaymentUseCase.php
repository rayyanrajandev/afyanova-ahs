<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\BillingInvoicePaymentReversalNotAllowedException;
use App\Modules\Billing\Application\Support\BillingFinancePostingService;
use App\Modules\Billing\Domain\Events\BillingCashierQueueUpdated;
use App\Modules\Billing\Domain\Events\InvoicePaymentReversed;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoicePaymentRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingInvoiceStatus;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceLineItemSourceModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class ReverseBillingInvoicePaymentUseCase
{
    public function __construct(
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly BillingInvoicePaymentRepositoryInterface $billingInvoicePaymentRepository,
        private readonly BillingInvoiceAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly BillingFinancePostingService $billingFinancePostingService,
    ) {}

    /**
     * @return array{invoice: array<string,mixed>, reversal: array<string,mixed>}|null
     */
    public function execute(
        string $billingInvoiceId,
        string $paymentId,
        float $amount,
        string $reason,
        ?string $approvalCaseReference = null,
        ?string $note = null,
        ?string $reversalAt = null,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $invoice = $this->billingInvoiceRepository->findById($billingInvoiceId);
        if (! $invoice) {
            return null;
        }

        $this->assertInvoiceAllowsReversal($invoice);

        $payment = $this->billingInvoicePaymentRepository->findByIdForBillingInvoice($billingInvoiceId, $paymentId);
        if (! $payment) {
            return null;
        }

        $this->assertTargetPaymentIsReversible($payment);

        $requestedAmount = round(max($amount, 0), 2);
        if ($requestedAmount <= 0) {
            throw new BillingInvoicePaymentReversalNotAllowedException('Reversal amount must be greater than zero.');
        }

        $originalAmount = round(abs((float) ($payment['amount'] ?? 0)), 2);
        $alreadyReversedAmount = $this->billingInvoicePaymentRepository->sumAppliedReversalsForPayment($paymentId);
        $remainingReversibleAmount = round(max($originalAmount - $alreadyReversedAmount, 0), 2);

        if ($remainingReversibleAmount <= 0) {
            throw new BillingInvoicePaymentReversalNotAllowedException('This payment has already been fully reversed.');
        }

        if ($requestedAmount > $remainingReversibleAmount) {
            throw new BillingInvoicePaymentReversalNotAllowedException(
                'Reversal amount exceeds the remaining reversible amount for the selected payment.',
            );
        }

        $previousPaidAmount = round((float) ($invoice['paid_amount'] ?? 0), 2);
        if ($requestedAmount > $previousPaidAmount) {
            throw new BillingInvoicePaymentReversalNotAllowedException(
                'Reversal amount exceeds the invoice paid amount.',
            );
        }

        $resolvedApprovalCaseReference = $this->normalizeNullableString($approvalCaseReference);
        $this->assertApprovalCaseReferencePolicy(
            invoice: $invoice,
            requestedAmount: $requestedAmount,
            approvalCaseReference: $resolvedApprovalCaseReference,
        );

        $totalAmount = round((float) ($invoice['total_amount'] ?? 0), 2);
        $resolvedPaidAmount = round(max($previousPaidAmount - $requestedAmount, 0), 2);
        $resolvedBalanceAmount = round(max($totalAmount - $resolvedPaidAmount, 0), 2);
        $resolvedStatus = $this->derivePaymentStatus($resolvedPaidAmount, $resolvedBalanceAmount);

        $resolvedReason = trim($reason);
        $resolvedNote = $this->normalizeNullableString($note);
        $resolvedReversalAt = $reversalAt ?: now()->toDateTimeString();

        $updatedInvoice = $this->billingInvoiceRepository->update($billingInvoiceId, [
            'status' => $resolvedStatus,
            'paid_amount' => $resolvedPaidAmount,
            'balance_amount' => $resolvedBalanceAmount,
        ]);

        if (! $updatedInvoice) {
            return null;
        }

        $reversalEntry = $this->billingInvoicePaymentRepository->create([
            'billing_invoice_id' => $billingInvoiceId,
            'recorded_by_user_id' => $actorId,
            'payment_at' => $resolvedReversalAt,
            'amount' => -1 * $requestedAmount,
            'cumulative_paid_amount' => $resolvedPaidAmount,
            'entry_type' => 'reversal',
            'reversal_of_payment_id' => $paymentId,
            'reversal_reason' => $resolvedReason,
            'approval_case_reference' => $resolvedApprovalCaseReference,
            'payer_type' => $payment['payer_type'] ?? null,
            'payment_method' => $payment['payment_method'] ?? null,
            'payment_reference' => $payment['payment_reference'] ?? null,
            'source_action' => 'billing-invoice.payment.reversed',
            'note' => $resolvedNote,
        ]);

        $this->billingFinancePostingService->syncInvoiceRecognition($updatedInvoice, $actorId);
        $this->billingFinancePostingService->recordPaymentReversalPosting($updatedInvoice, $reversalEntry, $actorId);

        $this->auditLogRepository->write(
            billingInvoiceId: $billingInvoiceId,
            action: 'billing-invoice.payment.reversed',
            actorId: $actorId,
            changes: [
                'reversal_of_payment_id' => ['before' => null, 'after' => $paymentId],
                'reversal_amount' => ['before' => null, 'after' => $requestedAmount],
                'reversal_reason' => ['before' => null, 'after' => $resolvedReason],
                'approval_case_reference' => ['before' => null, 'after' => $resolvedApprovalCaseReference],
                'status' => [
                    'before' => $invoice['status'] ?? null,
                    'after' => $updatedInvoice['status'] ?? null,
                ],
                'paid_amount' => [
                    'before' => $invoice['paid_amount'] ?? null,
                    'after' => $updatedInvoice['paid_amount'] ?? null,
                ],
                'balance_amount' => [
                    'before' => $invoice['balance_amount'] ?? null,
                    'after' => $updatedInvoice['balance_amount'] ?? null,
                ],
            ],
        );

        event(new BillingCashierQueueUpdated($updatedInvoice['facility_id'] ?? null));
        event(new InvoicePaymentReversed(
            billingInvoiceId: $billingInvoiceId,
            patientId: isset($updatedInvoice['patient_id']) ? (string) $updatedInvoice['patient_id'] : null,
            status: (string) $updatedInvoice['status'],
            sources: BillingInvoiceLineItemSourceModel::extractSourceRefs(
                is_array($updatedInvoice['line_items'] ?? null) ? $updatedInvoice['line_items'] : null,
            ),
        ));

        return [
            'invoice' => $updatedInvoice,
            'reversal' => $reversalEntry,
        ];
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function assertInvoiceAllowsReversal(array $invoice): void
    {
        $status = (string) ($invoice['status'] ?? '');

        if ($status === BillingInvoiceStatus::DRAFT->value) {
            throw new BillingInvoicePaymentReversalNotAllowedException(
                'Billing invoice payment reversal is not allowed while invoice is draft.',
            );
        }

        if ($status === BillingInvoiceStatus::CANCELLED->value || $status === BillingInvoiceStatus::VOIDED->value) {
            throw new BillingInvoicePaymentReversalNotAllowedException(
                'Billing invoice payment reversal is not allowed for cancelled or voided invoices.',
            );
        }
    }

    /**
     * @param array<string,mixed> $payment
     */
    private function assertTargetPaymentIsReversible(array $payment): void
    {
        $entryType = (string) ($payment['entry_type'] ?? 'payment');
        if ($entryType !== 'payment') {
            throw new BillingInvoicePaymentReversalNotAllowedException(
                'Only original payment ledger entries can be reversed.',
            );
        }

        $amount = round((float) ($payment['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new BillingInvoicePaymentReversalNotAllowedException(
                'Selected payment entry is not eligible for reversal.',
            );
        }
    }

    private function derivePaymentStatus(float $paidAmount, float $balanceAmount): string
    {
        if ($balanceAmount <= 0 && $paidAmount > 0) {
            return BillingInvoiceStatus::PAID->value;
        }

        if ($paidAmount > 0 && $balanceAmount > 0) {
            return BillingInvoiceStatus::PARTIALLY_PAID->value;
        }

        return BillingInvoiceStatus::ISSUED->value;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function assertApprovalCaseReferencePolicy(
        array $invoice,
        float $requestedAmount,
        ?string $approvalCaseReference
    ): void
    {
        $requireForPaidInvoiceReversals = (bool) config(
            'billing.payments.reversal.approval_case_reference_required_for_paid_invoice_reversals',
            false
        );
        $invoiceStatus = (string) ($invoice['status'] ?? '');

        if (
            $requireForPaidInvoiceReversals
            && $invoiceStatus === BillingInvoiceStatus::PAID->value
            && $approvalCaseReference === null
        ) {
            throw new BillingInvoicePaymentReversalNotAllowedException(
                'Approval case reference is required for reversals on paid invoices.',
            );
        }

        $threshold = (float) config('billing.payments.reversal.approval_case_reference_required_at_or_above_amount', 0);

        if ($threshold <= 0) {
            return;
        }

        if ($requestedAmount < $threshold) {
            return;
        }

        if ($approvalCaseReference !== null) {
            return;
        }

        throw new BillingInvoicePaymentReversalNotAllowedException(
            sprintf(
                'Approval case reference is required for reversals at or above %.2f.',
                $threshold,
            ),
        );
    }
}
