<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseTransferRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseTransferModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventoryWarehouseTransferRepository implements InventoryWarehouseTransferRepositoryInterface
{
    /**
     * @return array<int, string>
     */
    private function transferRelations(): array
    {
        return [
            'sourceWarehouse',
            'destinationWarehouse',
            'lines.item',
            'lines.batch',
            'lines.reservations',
        ];
    }

    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $transfer = new InventoryWarehouseTransferModel();
        $transfer->fill($attributes);
        $transfer->save();

        return $transfer->load($this->transferRelations())->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventoryWarehouseTransferModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $transfer = $query->with($this->transferRelations())->find($id);

        return $transfer?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventoryWarehouseTransferModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $transfer = $query->find($id);
        if (! $transfer) {
            return null;
        }

        $transfer->fill($attributes);
        $transfer->save();

        return $transfer->load($this->transferRelations())->toArray();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $varianceReviewStatus,
        ?string $sourceWarehouseId,
        ?string $destinationWarehouseId,
        int $page,
        int $perPage
    ): array {
        $builder = InventoryWarehouseTransferModel::query()->with($this->transferRelations());
        $this->applyPlatformScopeIfEnabled($builder);

        if ($query !== null && trim($query) !== '') {
            $term = '%' . trim($query) . '%';
            $builder->where(function (Builder $q) use ($term) {
                $q->where('transfer_number', 'LIKE', $term)
                    ->orWhere('reason', 'LIKE', $term)
                    ->orWhere('notes', 'LIKE', $term);
            });
        }

        if ($status !== null && trim($status) !== '') {
            $builder->where('status', $status);
        }

        if ($varianceReviewStatus !== null && trim($varianceReviewStatus) !== '') {
            $normalizedReviewStatus = trim($varianceReviewStatus);

            $builder
                ->where('status', 'received')
                ->whereHas('lines', static function (Builder $lineQuery): void {
                    $lineQuery->where('receipt_variance_quantity', '>', 0);
                });

            if ($normalizedReviewStatus === 'pending') {
                $builder->where(static function (Builder $reviewQuery): void {
                    $reviewQuery
                        ->whereNull('receipt_variance_review_status')
                        ->orWhere('receipt_variance_review_status', 'pending');
                });
            } elseif ($normalizedReviewStatus === 'reviewed') {
                $builder->where('receipt_variance_review_status', 'reviewed');
            }
        }

        if ($sourceWarehouseId !== null && trim($sourceWarehouseId) !== '') {
            $builder->where('source_warehouse_id', $sourceWarehouseId);
        }

        if ($destinationWarehouseId !== null && trim($destinationWarehouseId) !== '') {
            $builder->where('destination_warehouse_id', $destinationWarehouseId);
        }

        $builder->orderByDesc('created_at');

        return $this->toSearchResult($builder->paginate(perPage: $perPage, page: $page));
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
        // 'inventory_procurement_platform_scoping' isn't a registered flag
        // key (the real ones are 'platform.multi_facility_scoping'/
        // 'platform.multi_tenant_isolation') — this silently returned false
        // regardless of the real flags' state.
        try {
            return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
                || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
        } catch (\Throwable) {
            return false;
        }
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->map(fn ($m) => $m->toArray())->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
