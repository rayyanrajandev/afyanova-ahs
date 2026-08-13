<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryStockMovementRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementSourceKind;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementType;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventoryStockMovementRepository implements InventoryStockMovementRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $movement = new InventoryStockMovementModel();
        $movement->fill($attributes);
        $movement->save();

        return $movement->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventoryStockMovementModel::query()
            ->with('item')
            ->whereKey($id);
        $this->applyPlatformScopeIfEnabled($query);

        return $query->first()?->toArray();
    }

    public function search(
        ?string $query,
        ?string $itemId,
        ?string $movementType,
        ?string $sourceKey,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        ?bool $isOpeningStock = null,
    ): array {
        $sortBy = in_array($sortBy, ['occurred_at', 'created_at', 'movement_type', 'quantity', 'quantity_delta', 'stock_after'], true)
            ? $sortBy
            : 'occurred_at';

        $queryBuilder = InventoryStockMovementModel::query()->with('item');
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyFilters(
            queryBuilder: $queryBuilder,
            query: $query,
            itemId: $itemId,
            movementType: $movementType,
            sourceKey: $sourceKey,
            actorType: $actorType,
            actorId: $actorId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            isOpeningStock: $isOpeningStock,
        );

        $paginator = $queryBuilder
            ->orderBy($sortBy, $sortDirection)
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toSearchResult($paginator);
    }

    public function summary(
        ?string $query,
        ?string $itemId,
        ?string $movementType,
        ?string $sourceKey,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?bool $isOpeningStock = null,
    ): array {
        $queryBuilder = InventoryStockMovementModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyFilters(
            queryBuilder: $queryBuilder,
            query: $query,
            itemId: $itemId,
            movementType: $movementType,
            sourceKey: $sourceKey,
            actorType: $actorType,
            actorId: $actorId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            isOpeningStock: $isOpeningStock,
        );

        return [
            'total' => (clone $queryBuilder)->count(),
            'receive' => (clone $queryBuilder)->where('movement_type', InventoryStockMovementType::RECEIVE->value)->count(),
            'issue' => (clone $queryBuilder)->where('movement_type', InventoryStockMovementType::ISSUE->value)->count(),
            'adjust' => (clone $queryBuilder)->where('movement_type', InventoryStockMovementType::ADJUST->value)->count(),
            'transfer' => (clone $queryBuilder)->where('movement_type', InventoryStockMovementType::TRANSFER->value)->count(),
            'reconciliationAdjustments' => (clone $queryBuilder)
                ->where('movement_type', InventoryStockMovementType::ADJUST->value)
                ->where('metadata->source', 'stock_reconciliation')
                ->count(),
            'reconciliationIncreases' => (clone $queryBuilder)
                ->where('movement_type', InventoryStockMovementType::ADJUST->value)
                ->where('adjustment_direction', 'increase')
                ->where('metadata->source', 'stock_reconciliation')
                ->count(),
            'reconciliationDecreases' => (clone $queryBuilder)
                ->where('movement_type', InventoryStockMovementType::ADJUST->value)
                ->where('adjustment_direction', 'decrease')
                ->where('metadata->source', 'stock_reconciliation')
                ->count(),
            'distinctItems' => (clone $queryBuilder)->distinct('item_id')->count('item_id'),
            'netQuantityDelta' => (float) ((clone $queryBuilder)->sum('quantity_delta') ?? 0),
        ];
    }

    private function applyFilters(
        Builder $queryBuilder,
        ?string $query,
        ?string $itemId,
        ?string $movementType,
        ?string $sourceKey,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?bool $isOpeningStock = null,
    ): void {
        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('reason', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhere('movement_type', 'like', $like)
                        ->orWhere('source_type', 'like', $like)
                        ->orWhere('source_id', 'like', $like)
                        ->orWhere('procurement_request_id', 'like', $like)
                        ->orWhere('metadata->source', 'like', $like)
                        ->orWhere('metadata->requestNumber', 'like', $like)
                        ->orWhere('metadata->purchaseOrderNumber', 'like', $like)
                        ->orWhere('metadata->sessionReference', 'like', $like)
                        ->orWhere('metadata->sourceSnapshot->order_number', 'like', $like)
                        ->orWhere('metadata->sourceSnapshot->procedure_number', 'like', $like)
                        ->orWhereHas('item', function (Builder $itemQuery) use ($like): void {
                            $itemQuery
                                ->where('item_code', 'like', $like)
                                ->orWhere('item_name', 'like', $like)
                                ->orWhere('category', 'like', $like);
                        });
                });
            })
            ->when($itemId, fn (Builder $builder, string $requestedItemId) => $builder->where('item_id', $requestedItemId))
            ->when($movementType, fn (Builder $builder, string $requestedMovementType) => $builder->where('movement_type', $requestedMovementType))
            ->when($sourceKey, fn (Builder $builder, string $requestedSourceKey) => $this->applySourceFilter($builder, $requestedSourceKey))
            ->when($actorType === 'system', fn (Builder $builder) => $builder->whereNull('actor_id'))
            ->when($actorType === 'user', fn (Builder $builder) => $builder->whereNotNull('actor_id'))
            ->when($actorId !== null, fn (Builder $builder) => $builder->where('actor_id', $actorId))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('occurred_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('occurred_at', '<=', $endDateTime))
            ->when($isOpeningStock !== null, fn (Builder $builder) => $builder->where('is_opening_stock', $isOpeningStock));
    }

    private function applySourceFilter(Builder $queryBuilder, string $sourceKey): void
    {
        match ($sourceKey) {
            InventoryStockMovementSourceKind::CLINICAL_CONSUMPTION->value => $queryBuilder
                ->where('metadata->source', 'clinical_catalog_consumption_recipe'),
            InventoryStockMovementSourceKind::PROCUREMENT_RECEIPT->value => $queryBuilder
                ->whereNotNull('procurement_request_id'),
            InventoryStockMovementSourceKind::WAREHOUSE_TRANSFER->value => $queryBuilder
                ->where('metadata->source', 'warehouse_transfer'),
            InventoryStockMovementSourceKind::STOCK_RECONCILIATION->value => $queryBuilder
                ->where('metadata->source', 'stock_reconciliation'),
            InventoryStockMovementSourceKind::MANUAL_ENTRY->value => $queryBuilder
                ->whereNull('procurement_request_id')
                ->whereNull('source_type')
                ->whereNull('source_id')
                ->whereNotNull('actor_id')
                ->where(function (Builder $builder): void {
                    $builder
                        ->whereNull('metadata->source')
                        ->orWhereNotIn('metadata->source', [
                            'warehouse_transfer',
                            'stock_reconciliation',
                            'clinical_catalog_consumption_recipe',
                        ]);
                }),
            InventoryStockMovementSourceKind::SYSTEM_GENERATED->value => $queryBuilder
                ->whereNull('actor_id')
                ->whereNull('procurement_request_id')
                ->where(function (Builder $builder): void {
                    $builder
                        ->whereNull('metadata->source')
                        ->orWhereNotIn('metadata->source', [
                            'warehouse_transfer',
                            'stock_reconciliation',
                            'clinical_catalog_consumption_recipe',
                        ]);
                }),
            default => null,
        };
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
                static fn (InventoryStockMovementModel $movement): array => $movement->toArray(),
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
}
