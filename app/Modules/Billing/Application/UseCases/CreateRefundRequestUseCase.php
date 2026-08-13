<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoicePaymentRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingRefundRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingRefundModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateRefundRequestUseCase
{
    public function __construct(
        private readonly BillingRefundRepositoryInterface $refundRepository,
        private readonly BillingInvoiceRepositoryInterface $invoiceRepository,
        private readonly BillingInvoicePaymentRepositoryInterface $paymentRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * Create a refund request
     *
     * @param array<string, mixed> $payload
     * @param int|null $actorId
     *
     * @return array<string, mixed>
     */
    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $invoice = null;
        $invoiceId = null;
        if (isset($payload['invoice_id']) && $payload['invoice_id'] !== null) {
            $invoiceId = (string) $payload['invoice_id'];
            $invoice = $this->invoiceRepository->findById($invoiceId);
        } elseif (isset($payload['invoice_number']) && trim((string) $payload['invoice_number']) !== '') {
            $invoice = $this->invoiceRepository->findByInvoiceNumber((string) $payload['invoice_number']);
            $invoiceId = $invoice['id'] ?? null;
        }

        $refundReason = $payload['refund_reason'] ?? 'overpayment';
        $refundAmount = (float) $payload['refund_amount'];

        // Get invoice
        if ($invoice === null || $invoiceId === null) {
            $invoiceReference = $payload['invoice_number'] ?? $payload['invoice_id'] ?? 'unknown';
            throw new \RuntimeException('Invoice not found: ' . $invoiceReference);
        }

        // Validate refund amount
        if ($refundAmount <= 0) {
            throw new \RuntimeException('Refund amount must be greater than 0.');
        }

        // Get payment if specified
        $paymentId = $payload['payment_id'] ?? null;
        $payment = null;
        if ($paymentId !== null) {
            $payment = $this->paymentRepository->findByIdForBillingInvoice($invoiceId, (string) $paymentId);
            if ($payment === null) {
                throw new \RuntimeException('Payment not found: ' . $paymentId);
            }
            // Validate refund doesn't exceed payment
            if ($refundAmount > $payment['amount']) {
                throw new \RuntimeException(
                    'Refund amount cannot exceed payment amount: ' . $payment['amount']
                );
            }
        }

        // Create refund request
        $refund = BillingRefundModel::create([
            'billing_invoice_id' => $invoiceId,
            'billing_invoice_payment_id' => $paymentId,
            'patient_id' => $invoice['patient_id'],
            'refund_reason' => $refundReason,
            'refund_amount' => $refundAmount,
            'refund_method' => $payload['refund_method'] ?? 'cash',
            'mobile_money_provider' => $payload['mobile_money_provider'] ?? null,
            'mobile_money_reference' => $payload['mobile_money_reference'] ?? null,
            'card_reference' => $payload['card_reference'] ?? null,
            'check_number' => $payload['check_number'] ?? null,
            'requested_by_user_id' => $actorId,
            'requested_at' => now(),
            'refund_status' => 'pending',
            'notes' => $payload['notes'] ?? null,
        ]);

        return $refund->toArray();
    }
}
