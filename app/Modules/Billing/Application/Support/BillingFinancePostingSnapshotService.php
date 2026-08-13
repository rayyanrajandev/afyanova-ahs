<?php

namespace App\Modules\Billing\Application\Support;

use App\Modules\Billing\Infrastructure\Models\BillingInvoicePaymentModel;
use App\Modules\Billing\Infrastructure\Models\BillingRefundModel;
use App\Modules\Billing\Infrastructure\Models\GLJournalEntryModel;
use App\Modules\Billing\Infrastructure\Models\RevenueRecognitionModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class BillingFinancePostingSnapshotService
{
    /**
     * @return array{revenueRecognitionReady:bool,glPostingReady:bool,missingTables:array<int,string>}
     */
    private function financeInfrastructureSummary(): array
    {
        $missingTables = [];
        $revenueRecognitionReady = $this->hasRevenueRecognitionTable();
        $glPostingReady = $this->hasGlJournalEntriesTable();

        if (! $revenueRecognitionReady) {
            $missingTables[] = 'revenue_recognition_records';
        }

        if (! $glPostingReady) {
            $missingTables[] = 'gl_journal_entries';
        }

        return [
            'revenueRecognitionReady' => $revenueRecognitionReady,
            'glPostingReady' => $glPostingReady,
            'missingTables' => $missingTables,
        ];
    }

    /**
     * @param array<int, string> $billingInvoiceIds
     * @return array<string, array<string, mixed>>
     */
    public function invoiceSummaries(array $billingInvoiceIds): array
    {
        $invoiceIds = collect($billingInvoiceIds)
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values();

        if ($invoiceIds->isEmpty()) {
            return [];
        }

        $infrastructure = $this->financeInfrastructureSummary();

        $recognitions = $this->hasRevenueRecognitionTable()
            ? RevenueRecognitionModel::query()
                ->whereIn('billing_invoice_id', $invoiceIds)
                ->get()
                ->keyBy('billing_invoice_id')
            : collect();

        $revenueRows = $this->hasGlJournalEntriesTable()
            ? GLJournalEntryModel::query()
                ->where('reference_type', 'revenue_recognition')
                ->whereIn('reference_id', $invoiceIds)
                ->get()
                ->groupBy('reference_id')
            : collect();

        $paymentRowsByInvoice = $this->hasPaymentAndGlTables()
            ? $this->paymentLedgerRowsByInvoice($invoiceIds->all())
            : [];
        $refundRowsByInvoice = $this->hasRefundAndGlTables()
            ? $this->refundLedgerRowsByInvoice($invoiceIds->all())
            : [];

        $summaries = [];

        foreach ($invoiceIds as $invoiceId) {
            /** @var RevenueRecognitionModel|null $recognition */
            $recognition = $recognitions->get($invoiceId);

            $summaries[$invoiceId] = [
                'infrastructure' => $infrastructure,
                'recognition' => [
                    'status' => $recognition !== null ? 'recognized' : 'pending',
                    'recognizedAt' => $recognition?->recognition_date?->toISOString(),
                    'recognitionMethod' => $recognition?->recognition_method,
                    'recognizedAmount' => $recognition !== null ? round((float) $recognition->amount_recognized, 2) : 0.0,
                    'adjustedAmount' => $recognition !== null ? round((float) $recognition->amount_adjusted, 2) : 0.0,
                    'netRevenue' => $recognition !== null ? round((float) $recognition->net_revenue, 2) : 0.0,
                ],
                'revenuePosting' => $this->summarizeLedgerRows($revenueRows->get($invoiceId, collect())),
                'paymentPosting' => $this->summarizeLedgerRows($paymentRowsByInvoice[$invoiceId] ?? collect()),
                'refundPosting' => $this->summarizeLedgerRows($refundRowsByInvoice[$invoiceId] ?? collect()),
            ];
        }

        return $summaries;
    }

    public function invoiceSummary(string $billingInvoiceId): array
    {
        return $this->invoiceSummaries([$billingInvoiceId])[$billingInvoiceId] ?? [
            'infrastructure' => $this->financeInfrastructureSummary(),
            'recognition' => [
                'status' => 'pending',
                'recognizedAt' => null,
                'recognitionMethod' => null,
                'recognizedAmount' => 0.0,
                'adjustedAmount' => 0.0,
                'netRevenue' => 0.0,
            ],
            'revenuePosting' => $this->summarizeLedgerRows(collect()),
            'paymentPosting' => $this->summarizeLedgerRows(collect()),
            'refundPosting' => $this->summarizeLedgerRows(collect()),
        ];
    }

    /**
     * @param array<int, string> $refundIds
     * @return array<string, array<string, mixed>>
     */
    public function refundSummaries(array $refundIds): array
    {
        $normalizedRefundIds = collect($refundIds)
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values();

        if ($normalizedRefundIds->isEmpty()) {
            return [];
        }

        $infrastructure = $this->financeInfrastructureSummary();

        $rowsByRefund = $this->hasGlJournalEntriesTable()
            ? GLJournalEntryModel::query()
                ->where('reference_type', 'refund')
                ->whereIn('reference_id', $normalizedRefundIds)
                ->get()
                ->groupBy('reference_id')
            : collect();

        $summaries = [];

        foreach ($normalizedRefundIds as $refundId) {
            $rows = $rowsByRefund->get($refundId, collect());

            $summaries[$refundId] = [
                'infrastructure' => $infrastructure,
                'payoutPosted' => $rows->where('status', 'posted')->isNotEmpty(),
                'ledger' => $this->summarizeLedgerRows($rows),
            ];
        }

        return $summaries;
    }

    public function refundSummary(string $refundId): array
    {
        return $this->refundSummaries([$refundId])[$refundId] ?? [
            'infrastructure' => $this->financeInfrastructureSummary(),
            'payoutPosted' => false,
            'ledger' => $this->summarizeLedgerRows(collect()),
        ];
    }

    public function discountApplicationSummary(?string $billingInvoiceId): array
    {
        if ($billingInvoiceId === null || trim($billingInvoiceId) === '') {
            return [
                'infrastructure' => $this->financeInfrastructureSummary(),
                'recognition' => [
                    'status' => 'pending',
                    'recognizedAt' => null,
                    'netRevenue' => 0.0,
                ],
            ];
        }

        $recognition = $this->hasRevenueRecognitionTable()
            ? RevenueRecognitionModel::query()
                ->where('billing_invoice_id', $billingInvoiceId)
                ->first()
            : null;

        return [
            'infrastructure' => $this->financeInfrastructureSummary(),
            'recognition' => [
                'status' => $recognition !== null ? 'recognized' : 'pending',
                'recognizedAt' => $recognition?->recognition_date?->toISOString(),
                'netRevenue' => $recognition !== null ? round((float) $recognition->net_revenue, 2) : 0.0,
            ],
        ];
    }

    /**
     * @param Collection<int, GLJournalEntryModel> $rows
     * @return array<string, mixed>
     */
    private function summarizeLedgerRows(Collection $rows): array
    {
        $latestPostingDate = $rows
            ->pluck('posting_date')
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'entryCount' => $rows->count(),
            'postedCount' => $rows->where('status', 'posted')->count(),
            'draftCount' => $rows->where('status', 'draft')->count(),
            'reversedCount' => $rows->where('status', 'reversed')->count(),
            'latestPostingDate' => $latestPostingDate?->toISOString(),
        ];
    }

    /**
     * @param array<int, string> $billingInvoiceIds
     * @return array<string, Collection<int, GLJournalEntryModel>>
     */
    private function paymentLedgerRowsByInvoice(array $billingInvoiceIds): array
    {
        if (! $this->hasPaymentAndGlTables()) {
            return [];
        }

        $payments = BillingInvoicePaymentModel::query()
            ->whereIn('billing_invoice_id', $billingInvoiceIds)
            ->get(['id', 'billing_invoice_id']);

        if ($payments->isEmpty()) {
            return [];
        }

        $rowsByPaymentId = GLJournalEntryModel::query()
            ->where('reference_type', 'payment')
            ->whereIn('reference_id', $payments->pluck('id'))
            ->get()
            ->groupBy('reference_id');

        $rowsByInvoiceId = [];

        foreach ($payments as $payment) {
            $invoiceId = (string) $payment->billing_invoice_id;
            $paymentId = (string) $payment->id;

            if (!array_key_exists($invoiceId, $rowsByInvoiceId)) {
                $rowsByInvoiceId[$invoiceId] = collect();
            }

            $rowsByInvoiceId[$invoiceId] = $rowsByInvoiceId[$invoiceId]->merge(
                $rowsByPaymentId->get($paymentId, collect()),
            );
        }

        return $rowsByInvoiceId;
    }

    /**
     * @param array<int, string> $billingInvoiceIds
     * @return array<string, Collection<int, GLJournalEntryModel>>
     */
    private function refundLedgerRowsByInvoice(array $billingInvoiceIds): array
    {
        if (! $this->hasRefundAndGlTables()) {
            return [];
        }

        $refunds = BillingRefundModel::query()
            ->whereIn('billing_invoice_id', $billingInvoiceIds)
            ->get(['id', 'billing_invoice_id']);

        if ($refunds->isEmpty()) {
            return [];
        }

        $rowsByRefundId = GLJournalEntryModel::query()
            ->where('reference_type', 'refund')
            ->whereIn('reference_id', $refunds->pluck('id'))
            ->get()
            ->groupBy('reference_id');

        $rowsByInvoiceId = [];

        foreach ($refunds as $refund) {
            $invoiceId = (string) $refund->billing_invoice_id;
            $refundId = (string) $refund->id;

            if (!array_key_exists($invoiceId, $rowsByInvoiceId)) {
                $rowsByInvoiceId[$invoiceId] = collect();
            }

            $rowsByInvoiceId[$invoiceId] = $rowsByInvoiceId[$invoiceId]->merge(
                $rowsByRefundId->get($refundId, collect()),
            );
        }

        return $rowsByInvoiceId;
    }

    private function hasRevenueRecognitionTable(): bool
    {
        return Schema::hasTable('revenue_recognition_records');
    }

    private function hasGlJournalEntriesTable(): bool
    {
        return Schema::hasTable('gl_journal_entries');
    }

    private function hasPaymentAndGlTables(): bool
    {
        return Schema::hasTable('billing_invoice_payments')
            && $this->hasGlJournalEntriesTable();
    }

    private function hasRefundAndGlTables(): bool
    {
        return Schema::hasTable('billing_refunds')
            && $this->hasGlJournalEntriesTable();
    }
}
