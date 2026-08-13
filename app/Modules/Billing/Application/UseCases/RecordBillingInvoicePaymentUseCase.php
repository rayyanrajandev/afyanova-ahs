<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Exceptions\BillingInvoicePaymentRecordingNotAllowedException;
use App\Modules\Billing\Application\Support\BillingFinancePostingService;
use App\Modules\Billing\Domain\Events\BillingCashierQueueUpdated;
use App\Modules\Billing\Domain\Events\InvoicePaymentRecorded;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoicePaymentRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingInvoiceStatus;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceLineItemSourceModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class RecordBillingInvoicePaymentUseCase
{
    public function __construct(
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly BillingInvoicePaymentRepositoryInterface $billingInvoicePaymentRepository,
        private readonly BillingInvoiceAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly BillingFinancePostingService $billingFinancePostingService,
    ) {}

    /**
     * @return array{invoice: array<string, mixed>, payment: array<string, mixed>}|null
     */
    public function execute(
        string $billingInvoiceId,
        float $amount,
        string $payerType,
        string $paymentMethod,
        ?string $paymentReference = null,
        ?string $note = null,
        ?string $paymentAt = null,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->billingInvoiceRepository->findById($billingInvoiceId);
        if (! $existing) {
            return null;
        }

        $this->assertPaymentRecordingAllowed($existing);

        $totalAmount = round((float) ($existing['total_amount'] ?? 0), 2);
        $previousPaidAmount = round((float) ($existing['paid_amount'] ?? 0), 2);
        $requestedAmount = round(max($amount, 0), 2);
        $remainingBalance = round(max($totalAmount - $previousPaidAmount, 0), 2);
        $paymentDelta = round(min($requestedAmount, $remainingBalance), 2);

        if ($paymentDelta <= 0) {
            throw new BillingInvoicePaymentRecordingNotAllowedException(
                'Billing invoice has no remaining balance to record.',
            );
        }

        $resolvedPaidAmount = round($previousPaidAmount + $paymentDelta, 2);
        $balanceAmount = round(max($totalAmount - $resolvedPaidAmount, 0), 2);
        $resolvedStatus = $balanceAmount <= 0
            ? BillingInvoiceStatus::PAID->value
            : BillingInvoiceStatus::PARTIALLY_PAID->value;

        $resolvedPaymentReference = $this->normalizeNullableString($paymentReference);
        $resolvedNote = $this->normalizeNullableString($note);
        $resolvedPaymentAt = $paymentAt ?: now()->toDateTimeString();

        $updatedInvoice = $this->billingInvoiceRepository->update($billingInvoiceId, [
            'status' => $resolvedStatus,
            'paid_amount' => $resolvedPaidAmount,
            'balance_amount' => $balanceAmount,
            'last_payment_at' => $resolvedPaymentAt,
            'last_payment_payer_type' => $payerType,
            'last_payment_method' => $paymentMethod,
            'last_payment_reference' => $resolvedPaymentReference,
        ]);

        if (! $updatedInvoice) {
            return null;
        }

        $createdPayment = $this->billingInvoicePaymentRepository->create([
            'billing_invoice_id' => $billingInvoiceId,
            'recorded_by_user_id' => $actorId,
            'payment_at' => $resolvedPaymentAt,
            'amount' => $paymentDelta,
            'cumulative_paid_amount' => $resolvedPaidAmount,
            'payer_type' => $payerType,
            'payment_method' => $paymentMethod,
            'payment_reference' => $resolvedPaymentReference,
            'source_action' => 'billing-invoice.payment.recorded',
            'note' => $resolvedNote,
        ]);

        $this->billingFinancePostingService->syncInvoiceRecognition($updatedInvoice, $actorId);
        $this->billingFinancePostingService->recordPaymentPosting($updatedInvoice, $createdPayment, $actorId);

        $this->auditLogRepository->write(
            billingInvoiceId: $billingInvoiceId,
            action: 'billing-invoice.payment.recorded',
            actorId: $actorId,
            changes: [
                'recorded_payment_amount' => [
                    'before' => null,
                    'after' => $paymentDelta,
                ],
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updatedInvoice['status'] ?? null,
                ],
                'paid_amount' => [
                    'before' => $existing['paid_amount'] ?? null,
                    'after' => $updatedInvoice['paid_amount'] ?? null,
                ],
                'balance_amount' => [
                    'before' => $existing['balance_amount'] ?? null,
                    'after' => $updatedInvoice['balance_amount'] ?? null,
                ],
                'last_payment_at' => [
                    'before' => $existing['last_payment_at'] ?? null,
                    'after' => $updatedInvoice['last_payment_at'] ?? null,
                ],
                'last_payment_payer_type' => [
                    'before' => $existing['last_payment_payer_type'] ?? null,
                    'after' => $updatedInvoice['last_payment_payer_type'] ?? null,
                ],
                'last_payment_method' => [
                    'before' => $existing['last_payment_method'] ?? null,
                    'after' => $updatedInvoice['last_payment_method'] ?? null,
                ],
                'last_payment_reference' => [
                    'before' => $existing['last_payment_reference'] ?? null,
                    'after' => $updatedInvoice['last_payment_reference'] ?? null,
                ],
            ],
        );

        event(new BillingCashierQueueUpdated($updatedInvoice['facility_id'] ?? null));
        event(new InvoicePaymentRecorded(
            billingInvoiceId: $billingInvoiceId,
            patientId: isset($updatedInvoice['patient_id']) ? (string) $updatedInvoice['patient_id'] : null,
            status: (string) $updatedInvoice['status'],
            sources: BillingInvoiceLineItemSourceModel::extractSourceRefs(
                is_array($updatedInvoice['line_items'] ?? null) ? $updatedInvoice['line_items'] : null,
            ),
        ));

        return [
            'invoice' => $updatedInvoice,
            'payment' => $createdPayment,
        ];
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function assertPaymentRecordingAllowed(array $invoice): void
    {
        $status = (string) ($invoice['status'] ?? '');

        if ($status === BillingInvoiceStatus::DRAFT->value) {
            throw new BillingInvoicePaymentRecordingNotAllowedException(
                'Billing invoice payment can only be recorded after the invoice is issued.',
            );
        }

        if ($status === BillingInvoiceStatus::CANCELLED->value || $status === BillingInvoiceStatus::VOIDED->value) {
            throw new BillingInvoicePaymentRecordingNotAllowedException(
                'Billing invoice payment cannot be recorded for cancelled or voided invoices.',
            );
        }

        if ($status === BillingInvoiceStatus::PAID->value) {
            throw new BillingInvoicePaymentRecordingNotAllowedException(
                'Billing invoice is already fully paid.',
            );
        }
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
