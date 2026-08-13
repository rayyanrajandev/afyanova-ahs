<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierLeadTimeRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventorySupplierLeadTimeModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventorySupplierLeadTimeRepository implements InventorySupplierLeadTimeRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $record = new InventorySupplierLeadTimeModel();
        $record->fill($attributes);
        $record->save();

        return $record->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventorySupplierLeadTimeModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query->find($id)?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventorySupplierLeadTimeModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $record = $query->find($id);
        if (! $record) {
            return null;
        }

        $record->fill($attributes);
        $record->save();

        return $record->toArray();
    }

    public function listBySupplier(string $supplierId, int $page, int $perPage): array
    {
        $query = InventorySupplierLeadTimeModel::query()
            ->where('supplier_id', $supplierId)
            ->orderByDesc('order_date');
        $this->applyPlatformScopeIfEnabled($query);

        return $this->toSearchResult($query->paginate(perPage: $perPage, page: $page));
    }

    public function listByItem(string $itemId, int $page, int $perPage): array
    {
        $query = InventorySupplierLeadTimeModel::query()
            ->where('item_id', $itemId)
            ->orderByDesc('order_date');
        $this->applyPlatformScopeIfEnabled($query);

        return $this->toSearchResult($query->paginate(perPage: $perPage, page: $page));
    }

    public function averageLeadTime(string $supplierId, ?string $itemId = null): ?float
    {
        $query = InventorySupplierLeadTimeModel::query()
            ->where('supplier_id', $supplierId)
            ->whereNotNull('actual_lead_time_days');
        $this->applyPlatformScopeIfEnabled($query);

        if ($itemId !== null) {
            $query->where('item_id', $itemId);
        }

        $avg = $query->avg('actual_lead_time_days');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    public function averageFulfillmentRate(string $supplierId, ?string $itemId = null): ?float
    {
        $query = InventorySupplierLeadTimeModel::query()
            ->where('supplier_id', $supplierId)
            ->whereNotNull('fulfillment_rate');
        $this->applyPlatformScopeIfEnabled($query);

        if ($itemId !== null) {
            $query->where('item_id', $itemId);
        }

        $avg = $query->avg('fulfillment_rate');

        return $avg !== null ? round((float) $avg, 2) : null;
    }

    public function recordDelivery(string $id, array $deliveryData): ?array
    {
        $query = InventorySupplierLeadTimeModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $record = $query->find($id);
        if (! $record) {
            return null;
        }

        $record->actual_delivery_date = $deliveryData['actual_delivery_date'];
        $record->quantity_received = $deliveryData['quantity_received'] ?? null;

        // Calculate actual lead time days
        if ($record->order_date && $record->actual_delivery_date) {
            $record->actual_lead_time_days = $record->order_date->diffInDays($record->actual_delivery_date);
        }

        // Calculate fulfillment rate
        if ($record->quantity_ordered && $record->quantity_ordered > 0 && $record->quantity_received !== null) {
            $record->fulfillment_rate = min(100, round(($record->quantity_received / $record->quantity_ordered) * 100, 2));
        }

        // Determine delivery status
        if ($record->expected_delivery_date && $record->actual_delivery_date) {
            $record->delivery_status = $record->actual_delivery_date->gt($record->expected_delivery_date)
                ? 'late'
                : 'on_time';
        } else {
            $record->delivery_status = 'on_time';
        }

        if ($record->quantity_ordered && $record->quantity_received !== null
            && $record->quantity_received < $record->quantity_ordered) {
            $record->delivery_status = 'partial';
        }

        $record->save();

        return $record->toArray();
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
