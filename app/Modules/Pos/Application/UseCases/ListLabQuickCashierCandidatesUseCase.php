<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Pos\Application\Support\LabQuickCashierSupport;
use Illuminate\Database\Eloquent\Builder;

class ListLabQuickCashierCandidatesUseCase
{
    public function __construct(
        private readonly LabQuickCashierSupport $labQuickCashierSupport,
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
        $statusFilter = $this->labQuickCashierSupport->isEligibleStatus($statusFilter) ? $statusFilter : null;

        $query = LaboratoryOrderModel::query()
            ->whereNull('entered_in_error_at')
            ->whereIn('status', $this->labQuickCashierSupport->eligibleStatuses())
            ->when(
                $statusFilter !== null,
                static fn (Builder $builder) => $builder->where('status', $statusFilter),
            );

        if ($searchQuery !== '') {
            $like = '%'.strtolower($searchQuery).'%';
            $patientIds = $this->labQuickCashierSupport->patientSearchIds($searchQuery);

            $query->where(function (Builder $builder) use ($like, $patientIds): void {
                $builder->whereRaw('LOWER(order_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(test_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(test_name) LIKE ?', [$like]);

                if ($patientIds !== []) {
                    $builder->orWhereIn('patient_id', $patientIds);
                }
            });
        }

        $this->labQuickCashierSupport->applyPlatformScopeIfEnabled($query);

        $orders = $query
            ->orderByDesc('resulted_at')
            ->orderByDesc('ordered_at')
            ->orderByDesc('updated_at')
            ->limit(250)
            ->get();

        $patientIndex = $this->labQuickCashierSupport->patientIndex($orders->pluck('patient_id')->all());
        $catalogIndex = $this->labQuickCashierSupport->clinicalCatalogIndex($orders->pluck('lab_test_catalog_item_id')->all());
        $invoicedIndex = $this->labQuickCashierSupport->invoicedSourceIndex($orders->pluck('patient_id')->all());
        $settledIndex = $this->labQuickCashierSupport->posSettledSourceIndex($orders->pluck('id')->all());
        $pricingMap = $this->labQuickCashierSupport->batchPricingIndex($orders, $catalogIndex, $currencyCode);

        $visibleRows = $orders
            ->map(function (LaboratoryOrderModel $order) use ($patientIndex, $catalogIndex, $currencyCode, $invoicedIndex, $settledIndex, $pricingMap): array {
                return $this->labQuickCashierSupport->candidateFromOrder(
                    order: $order,
                    patient: $patientIndex[(string) $order->patient_id] ?? null,
                    catalogItem: $catalogIndex[(string) $order->lab_test_catalog_item_id] ?? null,
                    currencyCode: $currencyCode,
                    invoicedIndex: $invoicedIndex,
                    settledIndex: $settledIndex,
                    pricing: $pricingMap[(string) $order->id] ?? null,
                );
            })
            ->filter(static fn (array $candidate): bool => ($candidate['pricing_status'] ?? null) === 'priced')
            ->filter(static fn (array $candidate): bool => ! (bool) ($candidate['already_invoiced'] ?? false))
            ->filter(static fn (array $candidate): bool => ! (bool) ($candidate['already_settled'] ?? false))
            ->values();

        $offset = ($page - 1) * $perPage;
        $pagedRows = $visibleRows->slice($offset, $perPage)->values()->all();

        return [
            'data' => $pagedRows,
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => $visibleRows->count(),
                'lastPage' => max((int) ceil(max($visibleRows->count(), 1) / $perPage), 1),
                'currencyCode' => $currencyCode,
                'visiblePatients' => $visibleRows
                    ->pluck('patient_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'statusBreakdown' => $visibleRows
                    ->groupBy(static fn (array $row): string => (string) ($row['source_status'] ?? 'unknown'))
                    ->map(static fn ($rows): int => $rows->count())
                    ->all(),
                'scannedOrderCount' => $orders->count(),
            ],
        ];
    }
}
