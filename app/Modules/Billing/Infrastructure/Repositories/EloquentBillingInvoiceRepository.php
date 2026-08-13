<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceLineItemSourceModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\GLJournalEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Billing\Infrastructure\Models\RevenueRecognitionModel;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EloquentBillingInvoiceRepository implements BillingInvoiceRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            $invoice = new BillingInvoiceModel();
            $invoice->fill($attributes);
            $invoice->save();
            $this->syncLineItemSources($invoice);

            return $invoice->toArray();
        });
    }

    public function findById(string $id): ?array
    {
        $query = BillingInvoiceModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $invoice = $query->find($id);

        return $invoice?->toArray();
    }

    public function findByInvoiceNumber(string $invoiceNumber): ?array
    {
        $query = BillingInvoiceModel::query()
            ->where('invoice_number', trim($invoiceNumber));
        $this->applyPlatformScopeIfEnabled($query);
        $invoice = $query->first();

        return $invoice?->toArray();
    }

    public function findMatchingDraft(
        string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $billingPayerContractId,
        string $currencyCode,
        ?string $encounterId = null,
    ): ?array {
        $query = BillingInvoiceModel::query()
            ->where('patient_id', $patientId)
            ->where('status', 'draft')
            ->where('currency_code', strtoupper(trim($currencyCode)));

        $this->applyPlatformScopeIfEnabled($query);

        $this->applyNullableColumnMatch($query, 'appointment_id', $appointmentId);
        $this->applyNullableColumnMatch($query, 'admission_id', $admissionId);
        $this->applyNullableColumnMatch($query, 'billing_payer_contract_id', $billingPayerContractId);
        $this->applyNullableColumnMatch($query, 'encounter_id', $encounterId);

        $invoice = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();

        return $invoice?->toArray();
    }

    public function findByLineItemSource(
        string $patientId,
        string $sourceWorkflowKind,
        string $sourceWorkflowId,
        ?string $excludeInvoiceId = null,
    ): ?array {
        $normalizedKind = strtolower(trim($sourceWorkflowKind));
        $normalizedId = trim($sourceWorkflowId);
        if ($normalizedKind === '' || $normalizedId === '') {
            return null;
        }

        $query = BillingInvoiceModel::query()
            ->where('patient_id', $patientId)
            ->whereNotIn('status', ['cancelled', 'voided'])
            ->whereHas('lineItemSources', function (Builder $sourceQuery) use ($normalizedKind, $normalizedId): void {
                $sourceQuery
                    ->where('source_workflow_kind', $normalizedKind)
                    ->where('source_workflow_id', $normalizedId);
            });

        $this->applyPlatformScopeIfEnabled($query);

        if ($excludeInvoiceId !== null && trim($excludeInvoiceId) !== '') {
            $query->where('id', '!=', trim($excludeInvoiceId));
        }

        $invoice = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();

        return $invoice?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        return DB::transaction(function () use ($id, $attributes): ?array {
            $query = BillingInvoiceModel::query();
            $this->applyPlatformScopeIfEnabled($query);
            $invoice = $query->find($id);
            if (! $invoice) {
                return null;
            }

            $invoice->fill($attributes);
            $invoice->save();

            if (array_key_exists('line_items', $attributes)) {
                $this->syncLineItemSources($invoice);
            }

            return $invoice->toArray();
        });
    }

    /**
     * Keeps billing_invoice_line_item_sources in sync with the invoice's own
     * line_items JSON -- the single indexed table both findByLineItemSource() and
     * ListBillingChargeCaptureCandidatesUseCase::invoicedSourceIndex() read from,
     * replacing what used to be two independent per-invoice JSON scans.
     */
    private function syncLineItemSources(BillingInvoiceModel $invoice): void
    {
        BillingInvoiceLineItemSourceModel::query()
            ->where('billing_invoice_id', $invoice->id)
            ->delete();

        $rows = [];
        foreach (($invoice->line_items ?? []) as $index => $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $sourceWorkflowKind = trim((string) ($lineItem['sourceWorkflowKind'] ?? ''));
            $sourceWorkflowId = trim((string) ($lineItem['sourceWorkflowId'] ?? ''));
            if ($sourceWorkflowKind === '' || $sourceWorkflowId === '') {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::orderedUuid(),
                'billing_invoice_id' => $invoice->id,
                'source_workflow_kind' => strtolower($sourceWorkflowKind),
                'source_workflow_id' => $sourceWorkflowId,
                'line_item_index' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            BillingInvoiceLineItemSourceModel::query()->insert($rows);
        }
    }

    public function existsByInvoiceNumber(string $invoiceNumber): bool
    {
        return BillingInvoiceModel::query()
            ->where('invoice_number', $invoiceNumber)
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?array $statuses,
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $paymentActivityFromDateTime,
        ?string $paymentActivityToDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['invoice_number', 'invoice_date', 'total_amount', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'invoice_date';

        $queryBuilder = BillingInvoiceModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('invoice_number', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($statuses, fn (Builder $builder, array $requestedStatuses) => $builder->whereIn('status', $requestedStatuses))
            ->when($currencyCode, fn (Builder $builder, string $requestedCurrencyCode) => $builder->where('currency_code', $requestedCurrencyCode))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('invoice_date', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('invoice_date', '<=', $endDateTime))
            ->when($paymentActivityFromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('last_payment_at', '>=', $startDateTime))
            ->when($paymentActivityToDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('last_payment_at', '<=', $endDateTime))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $paymentActivityFromDateTime,
        ?string $paymentActivityToDateTime
    ): array {
        $queryBuilder = BillingInvoiceModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('invoice_number', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($currencyCode, fn (Builder $builder, string $requestedCurrencyCode) => $builder->where('currency_code', $requestedCurrencyCode))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('invoice_date', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('invoice_date', '<=', $endDateTime))
            ->when($paymentActivityFromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('last_payment_at', '>=', $startDateTime))
            ->when($paymentActivityToDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('last_payment_at', '<=', $endDateTime));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'draft' => 0,
            'issued' => 0,
            'partially_paid' => 0,
            'paid' => 0,
            'cancelled' => 0,
            'voided' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'other' && $status !== 'total') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    public function billingDepartmentOptions(): array
    {
        $billingServiceTypes = ['Clinical', 'Diagnostic', 'Pharmacy'];

        $catalogQuery = ChargeableItemModel::query()
            ->where('status', 'active')
            ->whereNotNull('department_id');
        $this->applyPlatformScopeIfEnabled($catalogQuery);

        $catalogRows = $catalogQuery
            ->orderBy('name')
            ->get(['department_id']);

        if ($catalogRows->isEmpty()) {
            return [];
        }

        $departmentIds = $catalogRows
            ->pluck('department_id')
            ->map(fn ($value): ?string => $this->normalizeNullableTrimmed($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $departmentQuery = DepartmentModel::query()
            ->where('status', 'active');
        $this->applyPlatformScopeIfEnabled($departmentQuery);
        $departmentQuery->where(function (Builder $builder) use ($billingServiceTypes, $departmentIds): void {
            $builder->where(function (Builder $billingBuilder) use ($billingServiceTypes): void {
                $billingBuilder
                    ->where('is_patient_facing', true)
                    ->whereIn('service_type', $billingServiceTypes);
            });

            if ($departmentIds !== []) {
                $builder->orWhereIn('id', $departmentIds);
            }
        });

        $options = [];
        foreach ($departmentQuery
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'service_type']) as $department) {
            $options[(string) $department->id] = [
                'id' => (string) $department->id,
                'code' => $this->normalizeNullableTrimmed($department->code ?? null),
                'name' => (string) ($department->name ?? ''),
                'serviceType' => $this->normalizeNullableTrimmed($department->service_type ?? null),
            ];
        }

        uasort($options, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return array_values($options);
    }

    public function financialControlSummary(
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $payerType,
        ?string $asOfDateTime,
        ?string $departmentFilter
    ): array {
        $asOf = $asOfDateTime !== null && trim($asOfDateTime) !== ''
            ? CarbonImmutable::parse($asOfDateTime)
            : CarbonImmutable::now();
        $departmentInvoiceIds = $this->matchingDepartmentInvoiceIds($departmentFilter, $currencyCode);

        $outstandingQuery = $this->baseOutstandingInvoiceQuery(
            currencyCode: $currencyCode,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
        $this->applyInvoiceIdFilter($outstandingQuery, 'id', $departmentInvoiceIds);

        $outstandingInvoiceCount = (clone $outstandingQuery)->count();
        $outstandingBalanceAmount = (float) ((clone $outstandingQuery)->sum('balance_amount'));

        $overdueQuery = (clone $outstandingQuery)
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<', $asOf->toDateTimeString());
        $overdueInvoiceCount = (clone $overdueQuery)->count();
        $overdueBalanceAmount = (float) ((clone $overdueQuery)->sum('balance_amount'));
        $averageDaysOverdue = $this->averageDaysOverdue((clone $overdueQuery)->get(['payment_due_at']));

        $agingBuckets = $this->buildAgingBuckets(
            invoices: (clone $outstandingQuery)->get(['payment_due_at', 'balance_amount']),
            asOf: $asOf,
        );

        $claimsBaseQuery = $this->baseClaimsQuery(
            payerType: $payerType,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
        $this->applyInvoiceIdFilter($claimsBaseQuery, 'invoice_id', $departmentInvoiceIds);

        $denialsQuery = (clone $claimsBaseQuery)->whereIn('status', ['rejected', 'partial']);
        $deniedClaimCount = (clone $denialsQuery)->where('status', 'rejected')->count();
        $partialDeniedClaimCount = (clone $denialsQuery)->where('status', 'partial')->count();
        $deniedAmountTotal = (float) ((clone $denialsQuery)->sum('rejected_amount'));

        $topDenialReasons = (clone $denialsQuery)
            ->selectRaw("COALESCE(NULLIF(decision_reason, ''), NULLIF(status_reason, ''), 'Unspecified') as reason")
            ->selectRaw('COUNT(*) as claim_count')
            ->selectRaw('COALESCE(SUM(rejected_amount), 0) as denied_amount_total')
            ->groupBy('reason')
            ->orderByDesc('denied_amount_total')
            ->orderByDesc('claim_count')
            ->limit(5)
            ->get()
            ->map(static function ($row): array {
                return [
                    'reason' => (string) ($row->reason ?? 'Unspecified'),
                    'claimCount' => (int) ($row->claim_count ?? 0),
                    'deniedAmountTotal' => round((float) ($row->denied_amount_total ?? 0), 2),
                ];
            })
            ->values()
            ->all();

        $settlementCohort = (clone $claimsBaseQuery)->whereIn('status', ['approved', 'partial']);
        $approvedAmountTotal = (float) ((clone $settlementCohort)->sum('approved_amount'));
        $settledAmountTotal = (float) ((clone $settlementCohort)->sum('settled_amount'));
        $pendingSettlementAmount = max($approvedAmountTotal - $settledAmountTotal, 0);
        $settlementRatePercent = $approvedAmountTotal > 0
            ? round(($settledAmountTotal / $approvedAmountTotal) * 100, 2)
            : 0.0;

        $reconciliationRows = (clone $settlementCohort)
            ->selectRaw('reconciliation_status, COUNT(*) as aggregate')
            ->groupBy('reconciliation_status')
            ->get();
        $reconciliationStatusCounts = [
            'pending' => 0,
            'partial_settled' => 0,
            'settled' => 0,
            'other' => 0,
            'total' => 0,
        ];
        foreach ($reconciliationRows as $row) {
            $status = strtolower(trim((string) ($row->reconciliation_status ?? '')));
            $aggregate = (int) ($row->aggregate ?? 0);

            if (array_key_exists($status, $reconciliationStatusCounts) && $status !== 'other' && $status !== 'total') {
                $reconciliationStatusCounts[$status] += $aggregate;
            } else {
                $reconciliationStatusCounts['other'] += $aggregate;
            }

            $reconciliationStatusCounts['total'] += $aggregate;
        }

        return [
            'generatedAt' => now()->toISOString(),
            'window' => [
                'from' => $fromDateTime,
                'to' => $toDateTime,
                'asOf' => $asOf->toISOString(),
                'currencyCode' => $currencyCode,
                'payerType' => $payerType,
                'departmentId' => $departmentFilter,
            ],
            'outstanding' => [
                'invoiceCount' => $outstandingInvoiceCount,
                'balanceAmountTotal' => round($outstandingBalanceAmount, 2),
                'overdueInvoiceCount' => $overdueInvoiceCount,
                'overdueBalanceAmountTotal' => round($overdueBalanceAmount, 2),
                'averageDaysOverdue' => $averageDaysOverdue,
            ],
            'agingBuckets' => $agingBuckets,
            'denials' => [
                'deniedClaimCount' => $deniedClaimCount,
                'partialDeniedClaimCount' => $partialDeniedClaimCount,
                'deniedAmountTotal' => round($deniedAmountTotal, 2),
                'topReasons' => $topDenialReasons,
            ],
            'settlement' => [
                'approvedAmountTotal' => round($approvedAmountTotal, 2),
                'settledAmountTotal' => round($settledAmountTotal, 2),
                'pendingSettlementAmount' => round($pendingSettlementAmount, 2),
                'settlementRatePercent' => $settlementRatePercent,
                'reconciliationStatusCounts' => $reconciliationStatusCounts,
            ],
        ];
    }

    public function revenueRecognitionSummary(
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $asOfDateTime,
        ?string $departmentFilter
    ): array {
        $asOf = $asOfDateTime !== null && trim($asOfDateTime) !== ''
            ? CarbonImmutable::parse($asOfDateTime)
            : CarbonImmutable::now();

        $eligibleInvoicesQuery = BillingInvoiceModel::query()
            ->whereNotIn('status', ['draft', 'cancelled', 'voided']);
        $this->applyPlatformScopeIfEnabled($eligibleInvoicesQuery);
        $eligibleInvoicesQuery
            ->when($currencyCode, fn (Builder $builder, string $requestedCurrencyCode) => $builder->where('currency_code', $requestedCurrencyCode))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('invoice_date', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('invoice_date', '<=', $endDateTime));

        $eligibleInvoices = $this->filterInvoicesByDepartment(
            (clone $eligibleInvoicesQuery)->get(['id', 'total_amount', 'currency_code', 'invoice_date', 'line_items']),
            $departmentFilter,
        );
        $eligibleInvoiceIds = $eligibleInvoices->pluck('id')->filter()->values();

        if (! Schema::hasTable('revenue_recognition_records')) {
            return $this->buildRevenueRecognitionSummaryWithoutFinanceTables(
                eligibleInvoices: $eligibleInvoices,
                eligibleInvoiceIds: $eligibleInvoiceIds->all(),
                currencyCode: $currencyCode,
                fromDateTime: $fromDateTime,
                toDateTime: $toDateTime,
                asOf: $asOf,
                departmentFilter: $departmentFilter,
            );
        }

        $recognitionQuery = RevenueRecognitionModel::query()
            ->join('billing_invoices', 'billing_invoices.id', '=', 'revenue_recognition_records.billing_invoice_id');
        if ($this->isPlatformScopingEnabled()) {
            $this->platformScopeQueryApplier->apply(
                $recognitionQuery,
                tenantColumn: 'billing_invoices.tenant_id',
                facilityColumn: 'billing_invoices.facility_id',
            );
        }
        $recognitionQuery
            ->when($currencyCode, fn (Builder $builder, string $requestedCurrencyCode) => $builder->where('billing_invoices.currency_code', $requestedCurrencyCode))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('revenue_recognition_records.recognition_date', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('revenue_recognition_records.recognition_date', '<=', $endDateTime));
        if ($departmentFilter !== null && trim($departmentFilter) !== '') {
            $this->applyInvoiceIdFilter($recognitionQuery, 'revenue_recognition_records.billing_invoice_id', $eligibleInvoiceIds->all());
        }

        $recognizedInvoiceIds = (clone $recognitionQuery)
            ->pluck('revenue_recognition_records.billing_invoice_id')
            ->filter()
            ->unique()
            ->values();
        $unrecognizedInvoices = $eligibleInvoices->reject(
            static fn ($invoice) => $recognizedInvoiceIds->contains($invoice->id)
        );

        $recognizedInvoiceCount = $recognizedInvoiceIds->count();
        $eligibleInvoiceCount = $eligibleInvoices->count();
        $recognizedAmountTotal = (float) ((clone $recognitionQuery)->sum('revenue_recognition_records.amount_recognized'));
        $adjustedAmountTotal = (float) ((clone $recognitionQuery)->sum('revenue_recognition_records.amount_adjusted'));
        $netRevenueTotal = (float) ((clone $recognitionQuery)->sum('revenue_recognition_records.net_revenue'));
        $latestRecognitionAt = (clone $recognitionQuery)->max('revenue_recognition_records.recognition_date');
        $coveragePercent = $eligibleInvoiceCount > 0
            ? round(($recognizedInvoiceCount / $eligibleInvoiceCount) * 100, 2)
            : 0.0;

        $recognitionMethodCounts = (clone $recognitionQuery)
            ->selectRaw('revenue_recognition_records.recognition_method, COUNT(*) as aggregate')
            ->groupBy('revenue_recognition_records.recognition_method')
            ->get()
            ->mapWithKeys(static function ($row): array {
                $method = strtolower(trim((string) ($row->recognition_method ?? 'unknown')));

                return [$method => (int) ($row->aggregate ?? 0)];
            })
            ->all();

        $glQuery = GLJournalEntryModel::query();
        $this->applyPlatformScopeIfEnabled($glQuery);
        $glQuery
            ->whereIn('reference_type', ['invoice', 'payment', 'revenue_recognition', 'refund'])
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('entry_date', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('entry_date', '<=', $endDateTime));
        if ($departmentFilter !== null && trim($departmentFilter) !== '') {
            $this->applyDepartmentInvoiceFilterToGlQuery($glQuery, $eligibleInvoiceIds->all());
        }

        if ($currencyCode !== null) {
            $glQuery->where(function (Builder $builder) use ($currencyCode): void {
                $builder
                    ->where(function (Builder $invoiceBuilder) use ($currencyCode): void {
                        $invoiceBuilder
                            ->whereIn('reference_type', ['invoice', 'revenue_recognition'])
                            ->whereExists(function ($subQuery) use ($currencyCode): void {
                                $subQuery
                                    ->selectRaw('1')
                                    ->from('billing_invoices')
                                    ->whereColumn('billing_invoices.id', 'gl_journal_entries.reference_id')
                                    ->where('billing_invoices.currency_code', $currencyCode);
                            });
                    })
                    ->orWhere(function (Builder $paymentBuilder) use ($currencyCode): void {
                        $paymentBuilder
                            ->where('reference_type', 'payment')
                            ->whereExists(function ($subQuery) use ($currencyCode): void {
                                $subQuery
                                    ->selectRaw('1')
                                    ->from('billing_invoice_payments')
                                    ->join('billing_invoices', 'billing_invoices.id', '=', 'billing_invoice_payments.billing_invoice_id')
                                    ->whereColumn('billing_invoice_payments.id', 'gl_journal_entries.reference_id')
                                    ->where('billing_invoices.currency_code', $currencyCode);
                            });
                    })
                    ->orWhere(function (Builder $refundBuilder) use ($currencyCode): void {
                        $refundBuilder
                            ->where('reference_type', 'refund')
                            ->whereExists(function ($subQuery) use ($currencyCode): void {
                                $subQuery
                                    ->selectRaw('1')
                                    ->from('billing_refunds')
                                    ->join('billing_invoices', 'billing_invoices.id', '=', 'billing_refunds.billing_invoice_id')
                                    ->whereColumn('billing_refunds.id', 'gl_journal_entries.reference_id')
                                    ->where('billing_invoices.currency_code', $currencyCode);
                            });
                    });
            });
        }

        $glStatusRows = (clone $glQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();
        $glStatusCounts = [
            'draft' => 0,
            'posted' => 0,
            'reversed' => 0,
            'other' => 0,
            'total' => 0,
        ];
        foreach ($glStatusRows as $row) {
            $status = strtolower(trim((string) ($row->status ?? '')));
            $aggregate = (int) ($row->aggregate ?? 0);

            if (array_key_exists($status, $glStatusCounts) && $status !== 'other' && $status !== 'total') {
                $glStatusCounts[$status] += $aggregate;
            } else {
                $glStatusCounts['other'] += $aggregate;
            }

            $glStatusCounts['total'] += $aggregate;
        }

        $draftPostingRows = (clone $glQuery)
            ->where('status', 'draft')
            ->get(['entry_date', 'debit_amount', 'credit_amount']);
        $staleDraftEntryCount = $draftPostingRows->filter(
            static fn ($row) => CarbonImmutable::parse((string) $row->entry_date)->lt($asOf->subDays(3))
        )->count();

        return [
            'generatedAt' => now()->toISOString(),
            'window' => [
                'from' => $fromDateTime,
                'to' => $toDateTime,
                'asOf' => $asOf->toISOString(),
                'currencyCode' => $currencyCode,
                'departmentId' => $departmentFilter,
            ],
            'infrastructure' => [
                'revenueRecognitionReady' => true,
                'glPostingReady' => true,
                'missingTables' => [],
            ],
            'recognition' => [
                'recognizedInvoiceCount' => $recognizedInvoiceCount,
                'recognizedAmountTotal' => round($recognizedAmountTotal, 2),
                'adjustedAmountTotal' => round($adjustedAmountTotal, 2),
                'netRevenueTotal' => round($netRevenueTotal, 2),
                'latestRecognitionAt' => $latestRecognitionAt !== null ? CarbonImmutable::parse((string) $latestRecognitionAt)->toISOString() : null,
                'recognitionMethodCounts' => $recognitionMethodCounts,
            ],
            'coverage' => [
                'eligibleInvoiceCount' => $eligibleInvoiceCount,
                'recognizedInvoiceCount' => $recognizedInvoiceCount,
                'unrecognizedInvoiceCount' => $unrecognizedInvoices->count(),
                'recognizedCoveragePercent' => $coveragePercent,
                'unrecognizedAmountTotal' => round((float) $unrecognizedInvoices->sum('total_amount'), 2),
            ],
            'glPosting' => [
                'entryStatusCounts' => $glStatusCounts,
                'draftDebitAmountTotal' => round((float) ((clone $glQuery)->where('status', 'draft')->sum('debit_amount')), 2),
                'draftCreditAmountTotal' => round((float) ((clone $glQuery)->where('status', 'draft')->sum('credit_amount')), 2),
                'postedDebitAmountTotal' => round((float) ((clone $glQuery)->where('status', 'posted')->sum('debit_amount')), 2),
                'postedCreditAmountTotal' => round((float) ((clone $glQuery)->where('status', 'posted')->sum('credit_amount')), 2),
                'staleDraftEntryCount' => $staleDraftEntryCount,
                'latestPostingDate' => (($latestPostingDate = (clone $glQuery)->whereNotNull('posting_date')->max('posting_date')) !== null)
                    ? CarbonImmutable::parse((string) $latestPostingDate)->toISOString()
                    : null,
                'openBatchCount' => (clone $glQuery)
                    ->where('status', 'draft')
                    ->whereNotNull('batch_id')
                    ->distinct('batch_id')
                    ->count('batch_id'),
            ],
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, BillingInvoiceModel> $eligibleInvoices
     * @param array<int, string> $eligibleInvoiceIds
     * @return array<string, mixed>
     */
    private function buildRevenueRecognitionSummaryWithoutFinanceTables(
        Collection $eligibleInvoices,
        array $eligibleInvoiceIds,
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        CarbonImmutable $asOf,
        ?string $departmentFilter,
    ): array {
        $unrecognizedAmountTotal = round((float) $eligibleInvoices->sum('total_amount'), 2);
        $hasGlJournalEntries = Schema::hasTable('gl_journal_entries');
        $missingTables = [];

        if (! Schema::hasTable('revenue_recognition_records')) {
            $missingTables[] = 'revenue_recognition_records';
        }

        if (! $hasGlJournalEntries) {
            $missingTables[] = 'gl_journal_entries';
        }

        $glStatusCounts = [
            'draft' => 0,
            'posted' => 0,
            'reversed' => 0,
            'other' => 0,
            'total' => 0,
        ];
        $staleDraftPostingCount = 0;
        $openDraftBatchCount = 0;
        $draftDebitAmountTotal = 0.0;
        $draftCreditAmountTotal = 0.0;
        $postedDebitAmountTotal = 0.0;
        $postedCreditAmountTotal = 0.0;
        $latestPostingDate = null;

        if ($hasGlJournalEntries) {
            $glQuery = GLJournalEntryModel::query();
            $this->applyPlatformScopeIfEnabled($glQuery);
            $glQuery
                ->whereIn('reference_type', ['invoice', 'payment', 'revenue_recognition', 'refund'])
                ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('entry_date', '>=', $startDateTime))
                ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('entry_date', '<=', $endDateTime));
            if ($departmentFilter !== null && trim($departmentFilter) !== '') {
                $this->applyDepartmentInvoiceFilterToGlQuery($glQuery, $eligibleInvoiceIds);
            }

            if ($currencyCode !== null) {
                $glQuery->where(function (Builder $builder) use ($currencyCode): void {
                    $builder
                        ->where(function (Builder $invoiceBuilder) use ($currencyCode): void {
                            $invoiceBuilder
                                ->whereIn('reference_type', ['invoice', 'revenue_recognition'])
                                ->whereExists(function ($subQuery) use ($currencyCode): void {
                                    $subQuery
                                        ->selectRaw('1')
                                        ->from('billing_invoices')
                                        ->whereColumn('billing_invoices.id', 'gl_journal_entries.reference_id')
                                        ->where('billing_invoices.currency_code', $currencyCode);
                                });
                        })
                        ->orWhere(function (Builder $paymentBuilder) use ($currencyCode): void {
                            $paymentBuilder
                                ->where('reference_type', 'payment')
                                ->whereExists(function ($subQuery) use ($currencyCode): void {
                                    $subQuery
                                        ->selectRaw('1')
                                        ->from('billing_invoice_payments')
                                        ->join('billing_invoices', 'billing_invoices.id', '=', 'billing_invoice_payments.billing_invoice_id')
                                        ->whereColumn('billing_invoice_payments.id', 'gl_journal_entries.reference_id')
                                        ->where('billing_invoices.currency_code', $currencyCode);
                                });
                        })
                        ->orWhere(function (Builder $refundBuilder) use ($currencyCode): void {
                            $refundBuilder
                                ->where('reference_type', 'refund')
                                ->whereExists(function ($subQuery) use ($currencyCode): void {
                                    $subQuery
                                        ->selectRaw('1')
                                        ->from('billing_refunds')
                                        ->join('billing_invoices', 'billing_invoices.id', '=', 'billing_refunds.billing_invoice_id')
                                        ->whereColumn('billing_refunds.id', 'gl_journal_entries.reference_id')
                                        ->where('billing_invoices.currency_code', $currencyCode);
                                });
                        });
                });
            }

            $glStatusRows = (clone $glQuery)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->get();

            foreach ($glStatusRows as $row) {
                $status = strtolower(trim((string) ($row->status ?? '')));
                $aggregate = (int) ($row->aggregate ?? 0);

                if (array_key_exists($status, $glStatusCounts) && $status !== 'other' && $status !== 'total') {
                    $glStatusCounts[$status] += $aggregate;
                } else {
                    $glStatusCounts['other'] += $aggregate;
                }

                $glStatusCounts['total'] += $aggregate;
            }

            $staleDraftPostingCount = (clone $glQuery)
                ->where('status', 'draft')
                ->where('entry_date', '<', $asOf->subDays(1)->toDateTimeString())
                ->count();

            $openDraftBatchCount = (clone $glQuery)
                ->where('status', 'draft')
                ->whereNotNull('batch_id')
                ->distinct('batch_id')
                ->count('batch_id');

            $draftDebitAmountTotal = round((float) ((clone $glQuery)->where('status', 'draft')->sum('debit_amount')), 2);
            $draftCreditAmountTotal = round((float) ((clone $glQuery)->where('status', 'draft')->sum('credit_amount')), 2);
            $postedDebitAmountTotal = round((float) ((clone $glQuery)->where('status', 'posted')->sum('debit_amount')), 2);
            $postedCreditAmountTotal = round((float) ((clone $glQuery)->where('status', 'posted')->sum('credit_amount')), 2);

            $resolvedLatestPostingDate = (clone $glQuery)->whereNotNull('posting_date')->max('posting_date');
            $latestPostingDate = $resolvedLatestPostingDate !== null
                ? CarbonImmutable::parse((string) $resolvedLatestPostingDate)->toISOString()
                : null;
        }

        return [
            'generatedAt' => now()->toISOString(),
            'window' => [
                'from' => $fromDateTime,
                'to' => $toDateTime,
                'asOf' => $asOf->toISOString(),
                'currencyCode' => $currencyCode,
                'departmentId' => $departmentFilter,
            ],
            'infrastructure' => [
                'revenueRecognitionReady' => false,
                'glPostingReady' => $hasGlJournalEntries,
                'missingTables' => $missingTables,
            ],
            'recognition' => [
                'recognizedInvoiceCount' => 0,
                'recognizedAmountTotal' => 0.0,
                'adjustedAmountTotal' => 0.0,
                'netRevenueTotal' => 0.0,
                'latestRecognitionAt' => null,
                'recognitionMethodCounts' => [],
            ],
            'coverage' => [
                'eligibleInvoiceCount' => $eligibleInvoices->count(),
                'recognizedInvoiceCount' => 0,
                'unrecognizedInvoiceCount' => $eligibleInvoices->count(),
                'recognizedCoveragePercent' => 0.0,
                'unrecognizedAmountTotal' => $unrecognizedAmountTotal,
            ],
            'glPosting' => [
                'entryStatusCounts' => $glStatusCounts,
                'draftDebitAmountTotal' => $draftDebitAmountTotal,
                'draftCreditAmountTotal' => $draftCreditAmountTotal,
                'postedDebitAmountTotal' => $postedDebitAmountTotal,
                'postedCreditAmountTotal' => $postedCreditAmountTotal,
                'staleDraftEntryCount' => $staleDraftPostingCount,
                'latestPostingDate' => $latestPostingDate,
                'openBatchCount' => $openDraftBatchCount,
            ],
        ];
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (BillingInvoiceModel $invoice): array => $invoice->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    private function baseOutstandingInvoiceQuery(
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime
    ): Builder {
        $query = BillingInvoiceModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->whereNotIn('status', ['draft', 'cancelled', 'voided'])
            ->where('balance_amount', '>', 0)
            ->when($currencyCode, fn (Builder $builder, string $requestedCurrencyCode) => $builder->where('currency_code', $requestedCurrencyCode))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('invoice_date', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('invoice_date', '<=', $endDateTime));
    }

    private function baseClaimsQuery(
        ?string $payerType,
        ?string $fromDateTime,
        ?string $toDateTime
    ): Builder {
        $query = ClaimsInsuranceCaseModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->when($payerType, fn (Builder $builder, string $requestedPayerType) => $builder->where('payer_type', $requestedPayerType))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('submitted_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('submitted_at', '<=', $endDateTime));
    }

    private function matchingDepartmentInvoiceIds(?string $departmentFilter, ?string $currencyCode): ?array
    {
        $targets = $this->resolveDepartmentFilterTargets($departmentFilter);
        if ($targets === null) {
            return null;
        }

        $query = BillingInvoiceModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $query->when($currencyCode, fn (Builder $builder, string $requestedCurrencyCode) => $builder->where('currency_code', $requestedCurrencyCode));

        return $this->filterInvoicesByDepartment(
            $query->get(['id', 'line_items']),
            $departmentFilter,
        )->pluck('id')->filter()->values()->all();
    }

    private function filterInvoicesByDepartment(Collection $invoices, ?string $departmentFilter): Collection
    {
        $targets = $this->resolveDepartmentFilterTargets($departmentFilter);
        if ($targets === null) {
            return $invoices;
        }

        $serviceCatalogDepartments = $this->serviceCatalogDepartmentMap(
            $invoices
                ->flatMap(function ($invoice): array {
                    $lineItems = $invoice->line_items ?? [];
                    if (! is_array($lineItems)) {
                        return [];
                    }

                    return array_values(array_filter(array_map(static function ($lineItem): ?string {
                        if (! is_array($lineItem)) {
                            return null;
                        }

                        $serviceCode = strtoupper(trim((string) ($lineItem['serviceCode'] ?? '')));

                        return $serviceCode !== '' ? $serviceCode : null;
                    }, $lineItems)));
                })
                ->unique()
                ->values()
                ->all(),
        );

        return $invoices->filter(function ($invoice) use ($targets, $serviceCatalogDepartments): bool {
            $lineItems = $invoice->line_items ?? [];
            if (! is_array($lineItems) || $lineItems === []) {
                return false;
            }

            foreach ($lineItems as $lineItem) {
                if (! is_array($lineItem)) {
                    continue;
                }

                if ($this->departmentMatchesTargets(
                    $this->normalizeNullableTrimmed($lineItem['departmentId'] ?? $lineItem['department_id'] ?? null),
                    $this->normalizeNullableTrimmed($lineItem['department'] ?? null),
                    $targets,
                )) {
                    return true;
                }

                $serviceCode = strtoupper(trim((string) ($lineItem['serviceCode'] ?? '')));
                if ($serviceCode !== '' && array_key_exists($serviceCode, $serviceCatalogDepartments)) {
                    $catalogDepartment = $serviceCatalogDepartments[$serviceCode];
                    if ($this->departmentMatchesTargets(
                        $catalogDepartment['department_id'] ?? null,
                        $catalogDepartment['department'] ?? null,
                        $targets,
                    )) {
                        return true;
                    }
                }
            }

            return false;
        })->values();
    }

    /**
     * @return array{departmentId: string|null, labels: array<int, string>}|null
     */
    private function resolveDepartmentFilterTargets(?string $departmentFilter): ?array
    {
        $normalized = $this->normalizeNullableTrimmed($departmentFilter);
        if ($normalized === null) {
            return null;
        }

        $departmentId = $this->looksLikeUuid($normalized) ? $normalized : null;
        $labels = [$this->normalizeDepartmentValue($normalized)];

        $query = DepartmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $department = $departmentId !== null
            ? $query->where('id', $departmentId)->first(['id', 'name', 'code'])
            : $query->where(function (Builder $builder) use ($normalized): void {
                $builder
                    ->where('name', $normalized)
                    ->orWhere('code', $normalized);
            })->first(['id', 'name', 'code']);

        if ($department !== null) {
            $departmentId = $departmentId ?? (string) $department->id;
            foreach ([(string) ($department->name ?? ''), (string) ($department->code ?? '')] as $candidate) {
                $normalizedCandidate = $this->normalizeDepartmentValue($candidate);
                if ($normalizedCandidate !== '') {
                    $labels[] = $normalizedCandidate;
                }
            }
        }

        return [
            'departmentId' => $departmentId,
            'labels' => array_values(array_unique(array_filter($labels))),
        ];
    }

    /**
     * @param  array{departmentId: string|null, labels: array<int, string>}  $targets
     */
    private function departmentMatchesTargets(?string $departmentId, ?string $departmentLabel, array $targets): bool
    {
        $normalizedId = $this->normalizeNullableTrimmed($departmentId);
        if ($normalizedId !== null && $targets['departmentId'] !== null && $normalizedId === $targets['departmentId']) {
            return true;
        }

        $normalizedLabel = $this->normalizeDepartmentValue((string) ($departmentLabel ?? ''));
        if ($normalizedLabel === '') {
            return false;
        }

        return in_array($normalizedLabel, $targets['labels'], true);
    }

    /**
     * @param  array<int, string>  $serviceCodes
     * @return array<string, array{department_id: string|null, department: string|null}>
     */
    private function serviceCatalogDepartmentMap(array $serviceCodes): array
    {
        if ($serviceCodes === []) {
            return [];
        }

        $query = ChargeableItemModel::query()
            ->whereIn('code', $serviceCodes)
            ->where('status', 'active');
        $this->applyPlatformScopeIfEnabled($query);

        $rows = [];
        foreach ($query->get(['code', 'department_id']) as $item) {
            $serviceCode = strtoupper(trim((string) ($item->code ?? '')));
            if ($serviceCode === '' || array_key_exists($serviceCode, $rows)) {
                continue;
            }

            $rows[$serviceCode] = [
                'department_id' => $this->normalizeNullableTrimmed($item->department_id ?? null),
                'department' => null,
            ];
        }

        return $rows;
    }

    private function applyInvoiceIdFilter(Builder $query, string $column, ?array $invoiceIds): void
    {
        if ($invoiceIds === null) {
            return;
        }

        if ($invoiceIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($column, $invoiceIds);
    }

    /**
     * @param  array<int, string>  $invoiceIds
     */
    private function applyDepartmentInvoiceFilterToGlQuery(Builder $query, array $invoiceIds): void
    {
        if ($invoiceIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $builder) use ($invoiceIds): void {
            $builder
                ->where(function (Builder $invoiceBuilder) use ($invoiceIds): void {
                    $invoiceBuilder
                        ->whereIn('reference_type', ['invoice', 'revenue_recognition'])
                        ->whereIn('reference_id', $invoiceIds);
                })
                ->orWhere(function (Builder $paymentBuilder) use ($invoiceIds): void {
                    $paymentBuilder
                        ->where('reference_type', 'payment')
                        ->whereExists(function ($subQuery) use ($invoiceIds): void {
                            $subQuery
                                ->selectRaw('1')
                                ->from('billing_invoice_payments')
                                ->whereColumn('billing_invoice_payments.id', 'gl_journal_entries.reference_id')
                                ->whereIn('billing_invoice_payments.billing_invoice_id', $invoiceIds);
                        });
                })
                ->orWhere(function (Builder $refundBuilder) use ($invoiceIds): void {
                    $refundBuilder
                        ->where('reference_type', 'refund')
                        ->whereExists(function ($subQuery) use ($invoiceIds): void {
                            $subQuery
                                ->selectRaw('1')
                                ->from('billing_refunds')
                                ->whereColumn('billing_refunds.id', 'gl_journal_entries.reference_id')
                                ->whereIn('billing_refunds.billing_invoice_id', $invoiceIds);
                        });
                });
        });
    }

    /**
     * @param  iterable<int, object>  $rows
     */
    private function averageDaysOverdue(iterable $rows): float
    {
        $totalDays = 0;
        $count = 0;

        foreach ($rows as $row) {
            $paymentDueAt = $row->payment_due_at ?? null;
            if ($paymentDueAt instanceof DateTimeInterface) {
                $dueAt = CarbonImmutable::instance((clone $paymentDueAt));
            } elseif (is_string($paymentDueAt) && trim($paymentDueAt) !== '') {
                $dueAt = CarbonImmutable::parse($paymentDueAt);
            } else {
                continue;
            }
            $days = max($dueAt->diffInDays(now(), false), 0);
            $totalDays += $days;
            $count++;
        }

        return $count > 0 ? round($totalDays / $count, 2) : 0.0;
    }

    private function applyNullableColumnMatch(
        Builder $query,
        string $column,
        ?string $value
    ): void {
        $normalized = is_string($value) ? trim($value) : null;

        if ($normalized === null || $normalized === '') {
            $query->whereNull($column);

            return;
        }

        $query->where($column, $normalized);
    }

    private function normalizeNullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeDepartmentValue(string $value): string
    {
        return strtolower(trim($value));
    }

    private function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        );
    }

    /**
     * @param  iterable<int, object>  $invoices
     * @return array<string, array<string, int|float>>
     */
    private function buildAgingBuckets(iterable $invoices, CarbonImmutable $asOf): array
    {
        $buckets = [
            'current' => ['invoiceCount' => 0, 'balanceAmountTotal' => 0.0],
            'days_1_30' => ['invoiceCount' => 0, 'balanceAmountTotal' => 0.0],
            'days_31_60' => ['invoiceCount' => 0, 'balanceAmountTotal' => 0.0],
            'days_61_90' => ['invoiceCount' => 0, 'balanceAmountTotal' => 0.0],
            'days_over_90' => ['invoiceCount' => 0, 'balanceAmountTotal' => 0.0],
        ];

        foreach ($invoices as $invoice) {
            $balance = round(max((float) ($invoice->balance_amount ?? 0), 0), 2);
            if ($balance <= 0) {
                continue;
            }

            $paymentDueAt = $invoice->payment_due_at ?? null;
            $bucketKey = 'current';

            if ($paymentDueAt instanceof DateTimeInterface) {
                $dueAt = CarbonImmutable::instance((clone $paymentDueAt));
                $days = max($dueAt->diffInDays($asOf, false), 0);
            } elseif (is_string($paymentDueAt) && trim($paymentDueAt) !== '') {
                $dueAt = CarbonImmutable::parse($paymentDueAt);
                $days = max($dueAt->diffInDays($asOf, false), 0);
            } else {
                $days = 0;
            }

            if ($days >= 1 && $days <= 30) {
                $bucketKey = 'days_1_30';
            } elseif ($days >= 31 && $days <= 60) {
                $bucketKey = 'days_31_60';
            } elseif ($days >= 61 && $days <= 90) {
                $bucketKey = 'days_61_90';
            } elseif ($days > 90) {
                $bucketKey = 'days_over_90';
            }

            $buckets[$bucketKey]['invoiceCount']++;
            $buckets[$bucketKey]['balanceAmountTotal'] = round(
                (float) $buckets[$bucketKey]['balanceAmountTotal'] + $balance,
                2
            );
        }

        return $buckets;
    }
}
