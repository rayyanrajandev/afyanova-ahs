<?php

namespace App\Modules\Billing\Application\Support;

use App\Modules\Billing\Domain\ValueObjects\BillingInvoiceStatus;
use App\Modules\Billing\Infrastructure\Models\GLJournalEntryModel;
use App\Modules\Billing\Infrastructure\Models\RevenueRecognitionModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class BillingFinancePostingService
{
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(private readonly CurrentPlatformScopeContextInterface $scopeContext) {}

    /**
     * @param array<string, mixed> $invoice
     */
    public function syncInvoiceRecognition(array $invoice, ?int $actorId = null): void
    {
        if (! $this->hasFinanceTables()) {
            return;
        }

        $invoiceId = (string) ($invoice['id'] ?? '');
        if ($invoiceId === '') {
            return;
        }

        $status = (string) ($invoice['status'] ?? '');
        $isRecognizable = in_array($status, [
            BillingInvoiceStatus::ISSUED->value,
            BillingInvoiceStatus::PARTIALLY_PAID->value,
            BillingInvoiceStatus::PAID->value,
        ], true);

        if (! $isRecognizable) {
            RevenueRecognitionModel::query()->where('billing_invoice_id', $invoiceId)->delete();
            GLJournalEntryModel::query()
                ->where('reference_type', 'revenue_recognition')
                ->where('reference_id', $invoiceId)
                ->delete();

            return;
        }

        $recognitionDate = $this->resolveDateTime(
            $invoice['invoice_date'] ?? null,
            now()->toDateTimeString(),
        );
        $recognizedAmount = round((float) ($invoice['total_amount'] ?? 0), 2);
        $adjustedAmount = round((float) ($invoice['discount_amount'] ?? 0), 2);
        $netRevenue = $recognizedAmount;

        RevenueRecognitionModel::query()->updateOrCreate(
            ['billing_invoice_id' => $invoiceId],
            [
                'recognition_date' => $recognitionDate,
                'recognition_method' => 'accrual',
                'amount_recognized' => $recognizedAmount,
                'amount_adjusted' => $adjustedAmount,
                'net_revenue' => $netRevenue,
                'gl_entry_ids' => [],
                'notes' => 'Synchronized from billing invoice lifecycle.',
            ],
        );

        GLJournalEntryModel::query()
            ->where('reference_type', 'revenue_recognition')
            ->where('reference_id', $invoiceId)
            ->delete();

        if ($netRevenue <= 0) {
            return;
        }

        $batchId = (string) Str::uuid();
        $tenantId = $this->resolveTenantId($invoice);
        $facilityId = $this->resolveFacilityId($invoice);

        $this->createGlEntry(
            tenantId: $tenantId,
            facilityId: $facilityId,
            referenceId: $invoiceId,
            referenceType: 'revenue_recognition',
            accountCode: '1200',
            accountName: 'Accounts Receivable - Patient Billing',
            debitAmount: $netRevenue,
            creditAmount: null,
            entryDate: $recognitionDate,
            postingDate: $recognitionDate,
            description: sprintf('Revenue recognition for invoice %s', (string) ($invoice['invoice_number'] ?? $invoiceId)),
            postedByUserId: $actorId,
            status: 'posted',
            batchId: $batchId,
        );
        $this->createGlEntry(
            tenantId: $tenantId,
            facilityId: $facilityId,
            referenceId: $invoiceId,
            referenceType: 'revenue_recognition',
            accountCode: '4000',
            accountName: 'Patient Service Revenue',
            debitAmount: null,
            creditAmount: $netRevenue,
            entryDate: $recognitionDate,
            postingDate: $recognitionDate,
            description: sprintf('Revenue recognition for invoice %s', (string) ($invoice['invoice_number'] ?? $invoiceId)),
            postedByUserId: $actorId,
            status: 'posted',
            batchId: $batchId,
        );
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $payment
     */
    public function recordPaymentPosting(array $invoice, array $payment, ?int $actorId = null): void
    {
        if (! $this->hasGlJournalEntriesTable()) {
            return;
        }

        $amount = round((float) ($payment['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return;
        }

        $paymentId = (string) ($payment['id'] ?? '');
        if ($paymentId === '') {
            return;
        }

        $paymentAt = $this->resolveDateTime($payment['payment_at'] ?? null, now()->toDateTimeString());
        $batchId = (string) Str::uuid();
        $tenantId = $this->resolveTenantId($invoice);
        $facilityId = $this->resolveFacilityId($invoice);
        [$cashAccountCode, $cashAccountName] = $this->cashAccountForMethod((string) ($payment['payment_method'] ?? 'cash'));

        $description = sprintf(
            'Payment posting for invoice %s',
            (string) ($invoice['invoice_number'] ?? ($invoice['id'] ?? $paymentId)),
        );

        $this->createGlEntry(
            tenantId: $tenantId,
            facilityId: $facilityId,
            referenceId: $paymentId,
            referenceType: 'payment',
            accountCode: $cashAccountCode,
            accountName: $cashAccountName,
            debitAmount: $amount,
            creditAmount: null,
            entryDate: $paymentAt,
            postingDate: $paymentAt,
            description: $description,
            postedByUserId: $actorId,
            status: 'posted',
            batchId: $batchId,
        );
        $this->createGlEntry(
            tenantId: $tenantId,
            facilityId: $facilityId,
            referenceId: $paymentId,
            referenceType: 'payment',
            accountCode: '1200',
            accountName: 'Accounts Receivable - Patient Billing',
            debitAmount: null,
            creditAmount: $amount,
            entryDate: $paymentAt,
            postingDate: $paymentAt,
            description: $description,
            postedByUserId: $actorId,
            status: 'posted',
            batchId: $batchId,
        );
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $reversal
     */
    public function recordPaymentReversalPosting(array $invoice, array $reversal, ?int $actorId = null): void
    {
        if (! $this->hasGlJournalEntriesTable()) {
            return;
        }

        $amount = round(abs((float) ($reversal['amount'] ?? 0)), 2);
        if ($amount <= 0) {
            return;
        }

        $reversalId = (string) ($reversal['id'] ?? '');
        if ($reversalId === '') {
            return;
        }

        $reversalAt = $this->resolveDateTime($reversal['payment_at'] ?? null, now()->toDateTimeString());
        $batchId = (string) Str::uuid();
        $tenantId = $this->resolveTenantId($invoice);
        $facilityId = $this->resolveFacilityId($invoice);
        [$cashAccountCode, $cashAccountName] = $this->cashAccountForMethod((string) ($reversal['payment_method'] ?? 'cash'));

        $description = sprintf(
            'Payment reversal for invoice %s',
            (string) ($invoice['invoice_number'] ?? ($invoice['id'] ?? $reversalId)),
        );

        $this->createGlEntry(
            tenantId: $tenantId,
            facilityId: $facilityId,
            referenceId: $reversalId,
            referenceType: 'payment',
            accountCode: '1200',
            accountName: 'Accounts Receivable - Patient Billing',
            debitAmount: $amount,
            creditAmount: null,
            entryDate: $reversalAt,
            postingDate: $reversalAt,
            description: $description,
            postedByUserId: $actorId,
            status: 'reversed',
            batchId: $batchId,
        );
        $this->createGlEntry(
            tenantId: $tenantId,
            facilityId: $facilityId,
            referenceId: $reversalId,
            referenceType: 'payment',
            accountCode: $cashAccountCode,
            accountName: $cashAccountName,
            debitAmount: null,
            creditAmount: $amount,
            entryDate: $reversalAt,
            postingDate: $reversalAt,
            description: $description,
            postedByUserId: $actorId,
            status: 'reversed',
            batchId: $batchId,
        );
    }

    /**
     * @param array<string, mixed> $refund
     */
    public function recordRefundPosting(array $refund, ?int $actorId = null): void
    {
        if (! $this->hasGlJournalEntriesTable()) {
            return;
        }

        $refundId = (string) ($refund['id'] ?? '');
        if ($refundId === '') {
            return;
        }

        $amount = round((float) ($refund['refund_amount'] ?? 0), 2);
        if ($amount <= 0) {
            return;
        }

        $processedAt = $this->resolveDateTime($refund['processed_at'] ?? null, now()->toDateTimeString());
        $batchId = (string) Str::uuid();
        $tenantId = $this->resolveTenantId($refund);
        $facilityId = $this->resolveFacilityId($refund);
        [$cashAccountCode, $cashAccountName] = $this->cashAccountForMethod((string) ($refund['refund_method'] ?? 'cash'));
        $description = sprintf(
            'Refund payout for invoice %s',
            (string) ($refund['invoice_number'] ?? ($refund['billing_invoice_id'] ?? $refundId)),
        );

        $this->createGlEntry(
            tenantId: $tenantId,
            facilityId: $facilityId,
            referenceId: $refundId,
            referenceType: 'refund',
            accountCode: '4090',
            accountName: 'Billing Refunds and Adjustments',
            debitAmount: $amount,
            creditAmount: null,
            entryDate: $processedAt,
            postingDate: $processedAt,
            description: $description,
            postedByUserId: $actorId,
            status: 'posted',
            batchId: $batchId,
        );
        $this->createGlEntry(
            tenantId: $tenantId,
            facilityId: $facilityId,
            referenceId: $refundId,
            referenceType: 'refund',
            accountCode: $cashAccountCode,
            accountName: $cashAccountName,
            debitAmount: null,
            creditAmount: $amount,
            entryDate: $processedAt,
            postingDate: $processedAt,
            description: $description,
            postedByUserId: $actorId,
            status: 'posted',
            batchId: $batchId,
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveTenantId(array $record): string
    {
        return (string) ($record['tenant_id'] ?? $record['invoice_tenant_id'] ?? $this->scopeContext->tenantId() ?? self::NIL_UUID);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveFacilityId(array $record): string
    {
        return (string) ($record['facility_id'] ?? $record['invoice_facility_id'] ?? $this->scopeContext->facilityId() ?? self::NIL_UUID);
    }

    private function resolveDateTime(mixed $value, string $fallback): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $fallback;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function cashAccountForMethod(string $paymentMethod): array
    {
        return match (strtolower(trim($paymentMethod))) {
            'mobile_money', 'lipa_namba' => ['1010', 'Mobile Money Clearing'],
            'card', 'bank_transfer', 'bank' => ['1020', 'Bank Clearing Account'],
            'check', 'cheque' => ['1030', 'Cheque Clearing Account'],
            default => ['1000', 'Cash on Hand'],
        };
    }

    private function createGlEntry(
        string $tenantId,
        string $facilityId,
        string $referenceId,
        string $referenceType,
        string $accountCode,
        string $accountName,
        ?float $debitAmount,
        ?float $creditAmount,
        string $entryDate,
        ?string $postingDate,
        string $description,
        ?int $postedByUserId,
        string $status,
        ?string $batchId,
    ): void {
        GLJournalEntryModel::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'account_code' => $accountCode,
            'account_name' => $accountName,
            'debit_amount' => $debitAmount !== null ? round($debitAmount, 2) : null,
            'credit_amount' => $creditAmount !== null ? round($creditAmount, 2) : null,
            'entry_date' => $entryDate,
            'posting_date' => $postingDate,
            'description' => $description,
            'posted_by_user_id' => $postedByUserId,
            'status' => $status,
            'batch_id' => $batchId,
        ]);
    }

    private function hasFinanceTables(): bool
    {
        return $this->hasGlJournalEntriesTable()
            && Schema::hasTable('revenue_recognition_records');
    }

    private function hasGlJournalEntriesTable(): bool
    {
        return Schema::hasTable('gl_journal_entries');
    }
}
