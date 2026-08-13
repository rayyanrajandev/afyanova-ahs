<?php

namespace App\Modules\Pharmacy\Application\Support\Reports;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ReportQueryHelper
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function applyPlatformScopeIfEnabled(Builder $query): void
    {
        try {
            if ($this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
                || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation')) {
                $this->platformScopeQueryApplier->apply($query);
            }
        } catch (\Throwable) {
        }
    }

    public function applyItemFilters(Builder $query, array $filters): void
    {
        if ($q = ($filters['q'] ?? null)) {
            $like = '%'.$q.'%';
            $query->where(function (Builder $b) use ($like) {
                $b->where('item_code', 'like', $like)
                    ->orWhere('item_name', 'like', $like);
            });
        }
        if ($category = ($filters['category'] ?? null)) {
            $query->where('category', $category);
        }
        if ($warehouseId = ($filters['warehouseId'] ?? null)) {
            $query->where('default_warehouse_id', $warehouseId);
        }
        if ($manufacturer = ($filters['manufacturer'] ?? null)) {
            $query->where('manufacturer', $manufacturer);
        }
    }

    public function applyDispensingFilters(Builder $query, array $filters): void
    {
        if ($from = ($filters['from'] ?? null)) {
            $query->where('inventory_dispensing_claim_links.created_at', '>=', Carbon::parse($from));
        }
        if ($to = ($filters['to'] ?? null)) {
            $query->where('inventory_dispensing_claim_links.created_at', '<=', Carbon::parse($to)->endOfDay());
        }
        if ($patientId = ($filters['patientId'] ?? null)) {
            $query->where('inventory_dispensing_claim_links.patient_id', $patientId);
        }
        if ($itemId = ($filters['itemId'] ?? null)) {
            $query->where('inventory_dispensing_claim_links.item_id', $itemId);
        }
        if ($payerName = ($filters['payerName'] ?? null)) {
            $query->where('inventory_dispensing_claim_links.payer_name', $payerName);
        }
        if ($claimStatus = ($filters['claimStatus'] ?? null)) {
            $query->where('inventory_dispensing_claim_links.claim_status', $claimStatus);
        }
    }

    public function stockState(float $currentStock, float $reorderLevel): string
    {
        if ($currentStock <= 0) return 'out_of_stock';
        if ($reorderLevel > 0 && $currentStock <= $reorderLevel) return 'low_stock';
        return 'healthy';
    }

    public function paginatorMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
        ];
    }
}
