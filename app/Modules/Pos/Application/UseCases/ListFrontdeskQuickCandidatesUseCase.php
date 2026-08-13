<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Pos\Application\Support\FrontdeskQuickCashierSupport;
use Illuminate\Database\Eloquent\Builder;

class ListFrontdeskQuickCandidatesUseCase
{
    public function __construct(
        private readonly FrontdeskQuickCashierSupport $frontdeskQuickCashierSupport,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
    ) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 12), 1), 100);
        $currencyCode = strtoupper(trim((string) ($filters['currencyCode'] ?? '')));
        $currencyCode = $currencyCode !== '' ? $currencyCode : $this->defaultCurrencyResolver->resolve();
        $searchQuery = trim((string) ($filters['q'] ?? ''));
        $statusFilter = strtolower(trim((string) ($filters['status'] ?? '')));
        $statusFilter = $this->frontdeskQuickCashierSupport->isEligibleStatus($statusFilter) ? $statusFilter : null;

        $patientIds = $searchQuery !== '' ? $this->frontdeskQuickCashierSupport->patientSearchIds($searchQuery) : null;

        $allCandidates = [];

        foreach (['laboratory_order', 'pharmacy_prescription', 'radiology_order', 'clinical_procedure_order', 'procedure'] as $kind) {
            $query = $this->frontdeskQuickCashierSupport->eligibleOrderQuery($kind);
            if ($query === null) {
                continue;
            }

            if ($searchQuery !== '') {
                $like = '%'.strtolower($searchQuery).'%';
                $query->where(function (Builder $builder) use ($like, $patientIds, $kind): void {
                    $this->applySearchToQuery($builder, $like, $patientIds, $kind);
                });
            }

            if ($statusFilter !== null) {
                $query->where('status', $statusFilter);
            }

            $orders = $query
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit(250)
                ->get();

            if ($orders->isEmpty()) {
                continue;
            }

            $config = $this->frontdeskQuickCashierSupport->sourceKindConfig($kind);
            $catalogFk = $config['catalog_fk'] ?? null;

            $catalogItemIds = $orders->pluck($catalogFk)->filter()->values()->all();
            $catalogIndex = $this->frontdeskQuickCashierSupport->clinicalCatalogIndex($catalogItemIds);
            $invoicedIndex = $this->frontdeskQuickCashierSupport->invoicedSourceIndex($orders->pluck('patient_id')->all());
            $settledIndex = $this->frontdeskQuickCashierSupport->posSettledSourceIndex($orders->pluck('id')->all());
            $patientIndex = $this->frontdeskQuickCashierSupport->patientIndex($orders->pluck('patient_id')->all());
            $pricingMap = $this->frontdeskQuickCashierSupport->batchPricingIndex($orders, $kind, $catalogFk, $catalogIndex, $currencyCode);

            foreach ($orders as $order) {
                $candidate = $this->frontdeskQuickCashierSupport->candidateFromOrder(
                    order: $order,
                    kind: $kind,
                    patient: $patientIndex[(string) $order->patient_id] ?? null,
                    catalogItem: $catalogIndex[(string) $order->{$catalogFk}] ?? null,
                    currencyCode: $currencyCode,
                    catalogFk: $catalogFk,
                    invoicedIndex: $invoicedIndex,
                    settledIndex: $settledIndex,
                    pricing: $pricingMap[(string) $order->id] ?? null,
                );

                if (($candidate['pricing_status'] ?? null) !== 'priced') {
                    continue;
                }

                if ((bool) ($candidate['already_invoiced'] ?? false)) {
                    continue;
                }

                if ((bool) ($candidate['already_settled'] ?? false)) {
                    continue;
                }

                $allCandidates[] = $candidate;
            }
        }

        $total = count($allCandidates);

        $offset = ($page - 1) * $perPage;
        $pagedRows = array_slice($allCandidates, $offset, $perPage);

        return [
            'data' => $pagedRows,
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => max((int) ceil(max($total, 1) / $perPage), 1),
                'currencyCode' => $currencyCode,
                'visiblePatients' => collect($allCandidates)
                    ->pluck('patient_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'sourceKindBreakdown' => collect($allCandidates)
                    ->groupBy(static fn (array $row): string => (string) ($row['source_kind'] ?? 'unknown'))
                    ->map(static fn ($rows): int => $rows->count())
                    ->all(),
                'statusBreakdown' => collect($allCandidates)
                    ->groupBy(static fn (array $row): string => (string) ($row['source_status'] ?? 'unknown'))
                    ->map(static fn ($rows): int => $rows->count())
                    ->all(),
            ],
        ];
    }

    private function applySearchToQuery(Builder $query, string $like, ?array $patientIds, string $kind): void
    {
        $searchColumns = match ($kind) {
            'laboratory_order' => ['order_number', 'test_code', 'test_name'],
            'pharmacy_prescription' => ['order_number', 'medication_code', 'medication_name'],
            'radiology_order' => ['order_number', 'procedure_code', 'study_description'],
            'clinical_procedure_order' => ['order_number', 'procedure_code', 'procedure_description'],
            'procedure' => ['procedure_number', 'procedure_type', 'procedure_name'],
            default => ['id'],
        };

        $query->where(function (Builder $builder) use ($like, $patientIds, $searchColumns): void {
            foreach ($searchColumns as $column) {
                $builder->orWhereRaw('LOWER('.$column.') LIKE ?', [$like]);
            }

            if ($patientIds !== null && $patientIds !== []) {
                $builder->orWhereIn('patient_id', $patientIds);
            }
        });
    }
}
