<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Application\Support\BillingFinancePostingService;
use App\Modules\Billing\Domain\Events\BillingCashierQueueUpdated;
use App\Modules\Billing\Domain\Events\InvoiceStatusChanged;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceAuditLogRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoicePaymentRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingInvoiceStatus;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceLineItemSourceModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateBillingInvoiceStatusUseCase
{
    public function __construct(
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly BillingInvoicePaymentRepositoryInterface $billingInvoicePaymentRepository,
        private readonly BillingInvoiceAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly BillingFinancePostingService $billingFinancePostingService,
    ) {}

    public function execute(
        string $id,
        string $status,
        ?string $reason,
        ?float $paidAmount,
        ?string $paymentPayerType = null,
        ?string $paymentMethod = null,
        ?string $paymentReference = null,
        ?int $actorId = null,
    ): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->billingInvoiceRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if ($status === 'issued') {
            $pricingContext = is_array($existing['pricing_context'] ?? null)
                ? $existing['pricing_context']
                : [];
            $claimReadiness = is_array($pricingContext['claimReadiness'] ?? null)
                ? $pricingContext['claimReadiness']
                : [];

            if ((bool) ($claimReadiness['coverageVerificationRequired'] ?? false)) {
                throw new \RuntimeException(
                    'Invoice cannot be issued until coverage verification is resolved.',
                );
            }
        }

        $totalAmount = (float) ($existing['total_amount'] ?? 0);
        $resolvedPaidAmount = $paidAmount !== null
            ? max($paidAmount, 0)
            : (float) ($existing['paid_amount'] ?? 0);

        if ($status === BillingInvoiceStatus::PAID->value && $paidAmount === null) {
            $resolvedPaidAmount = $totalAmount;
        }

        $resolvedPaidAmount = round(min($resolvedPaidAmount, $totalAmount), 2);
        $balanceAmount = round(max($totalAmount - $resolvedPaidAmount, 0), 2);
        $previousPaidAmount = (float) ($existing['paid_amount'] ?? 0);
        $didIncreasePaidAmount = $resolvedPaidAmount > $previousPaidAmount;

        $lastPaymentAt = $existing['last_payment_at'] ?? null;
        $lastPaymentPayerType = $existing['last_payment_payer_type'] ?? null;
        $lastPaymentMethod = $existing['last_payment_method'] ?? null;
        $lastPaymentReference = $existing['last_payment_reference'] ?? null;
        if ($didIncreasePaidAmount) {
            $lastPaymentAt = now();
            $lastPaymentPayerType = $this->normalizeNullableString($paymentPayerType);
            $lastPaymentMethod = $this->normalizeNullableString($paymentMethod);
            $lastPaymentReference = $this->normalizeNullableString($paymentReference);
        }

        $updated = $this->billingInvoiceRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
            'paid_amount' => $resolvedPaidAmount,
            'last_payment_at' => $lastPaymentAt,
            'last_payment_payer_type' => $lastPaymentPayerType,
            'last_payment_method' => $lastPaymentMethod,
            'last_payment_reference' => $lastPaymentReference,
            'balance_amount' => $balanceAmount,
        ]);

        if (! $updated) {
            return null;
        }

        if ($didIncreasePaidAmount) {
            $paymentDelta = round($resolvedPaidAmount - $previousPaidAmount, 2);

            if ($paymentDelta > 0) {
                $createdPayment = $this->billingInvoicePaymentRepository->create([
                    'billing_invoice_id' => $id,
                    'recorded_by_user_id' => $actorId,
                    'payment_at' => $updated['last_payment_at'] ?? now(),
                    'amount' => $paymentDelta,
                    'cumulative_paid_amount' => $resolvedPaidAmount,
                    'payer_type' => $lastPaymentPayerType,
                    'payment_method' => $lastPaymentMethod,
                    'payment_reference' => $lastPaymentReference,
                    'source_action' => 'billing-invoice.status.updated',
                    'note' => $this->normalizeNullableString($reason),
                ]);

                $this->billingFinancePostingService->recordPaymentPosting($updated, $createdPayment, $actorId);
            }
        }

        $this->billingFinancePostingService->syncInvoiceRecognition($updated, $actorId);

        $reasonRequired = in_array($status, [
            BillingInvoiceStatus::CANCELLED->value,
            BillingInvoiceStatus::VOIDED->value,
        ], true);

        $this->auditLogRepository->write(
            billingInvoiceId: $id,
            action: 'billing-invoice.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
                'paid_amount' => [
                    'before' => $existing['paid_amount'] ?? null,
                    'after' => $updated['paid_amount'] ?? null,
                ],
                'last_payment_at' => [
                    'before' => $existing['last_payment_at'] ?? null,
                    'after' => $updated['last_payment_at'] ?? null,
                ],
                'last_payment_payer_type' => [
                    'before' => $existing['last_payment_payer_type'] ?? null,
                    'after' => $updated['last_payment_payer_type'] ?? null,
                ],
                'last_payment_method' => [
                    'before' => $existing['last_payment_method'] ?? null,
                    'after' => $updated['last_payment_method'] ?? null,
                ],
                'last_payment_reference' => [
                    'before' => $existing['last_payment_reference'] ?? null,
                    'after' => $updated['last_payment_reference'] ?? null,
                ],
                'balance_amount' => [
                    'before' => $existing['balance_amount'] ?? null,
                    'after' => $updated['balance_amount'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
            ],
        );

        event(new BillingCashierQueueUpdated($updated['facility_id'] ?? null));
        event(new InvoiceStatusChanged(
            billingInvoiceId: $id,
            patientId: isset($updated['patient_id']) ? (string) $updated['patient_id'] : null,
            status: (string) $updated['status'],
            sources: BillingInvoiceLineItemSourceModel::extractSourceRefs(
                is_array($updated['line_items'] ?? null) ? $updated['line_items'] : null,
            ),
        ));

        return $updated;
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
