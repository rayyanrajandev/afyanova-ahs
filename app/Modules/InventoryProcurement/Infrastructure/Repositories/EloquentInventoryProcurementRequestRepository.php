<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryProcurementRequestModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventoryProcurementRequestRepository implements InventoryProcurementRequestRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $request = new InventoryProcurementRequestModel();
        $request->fill($attributes);
        $request->save();

        return $this->findById((string) $request->id) ?? $request->toArray();
    }

    public function findById(string $id): ?array
    {
        return $this->baseQuery()
            ->where('inventory_procurement_requests.id', $id)
            ->first()
            ?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventoryProcurementRequestModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $request = $query->find($id);
        if (! $request) {
            return null;
        }

        $request->fill($attributes);
        $request->save();

        return $this->findById($id);
    }

    public function existsByRequestNumber(string $requestNumber): bool
    {
        return InventoryProcurementRequestModel::query()
            ->where('request_number', $requestNumber)
            ->exists();
    }

    public function latestBySourceDepartmentRequisitionLineIds(array $lineIds): array
    {
        $lineIds = array_values(array_unique(array_filter(
            array_map(static fn ($lineId): string => trim((string) $lineId), $lineIds),
        )));

        if ($lineIds === []) {
            return [];
        }

        $requests = $this->baseQuery()
            ->whereIn('inventory_procurement_requests.source_department_requisition_line_id', $lineIds)
            ->orderByDesc('inventory_procurement_requests.created_at')
            ->get();

        $latestByLineId = [];
        foreach ($requests as $request) {
            $payload = $request->toArray();
            $lineId = (string) ($payload['source_department_requisition_line_id'] ?? '');

            if ($lineId !== '' && ! isset($latestByLineId[$lineId])) {
                $latestByLineId[$lineId] = $payload;
            }
        }

        return $latestByLineId;
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $itemId,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['request_number', 'status', 'needed_by', 'created_at', 'updated_at'], true)
            ? 'inventory_procurement_requests.'.$sortBy
            : 'inventory_procurement_requests.created_at';

        $queryBuilder = $this->baseQuery();

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('inventory_procurement_requests.request_number', 'like', $like)
                        ->orWhere('source_department_requisitions.requisition_number', 'like', $like)
                        ->orWhere('source_department_requisitions.requesting_department', 'like', $like)
                        ->orWhere('inventory_procurement_requests.source_department_requisition_id', 'like', $like)
                        ->orWhere('inventory_procurement_requests.supplier_name', 'like', $like)
                        ->orWhere('inventory_procurement_requests.status_reason', 'like', $like)
                        ->orWhere('inventory_items.item_code', 'like', $like)
                        ->orWhere('inventory_items.item_name', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('inventory_procurement_requests.status', $requestedStatus))
            ->when($itemId, fn (Builder $builder, string $requestedItemId) => $builder->where('inventory_procurement_requests.item_id', $requestedItemId))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('inventory_procurement_requests.created_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('inventory_procurement_requests.created_at', '<=', $endDateTime))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply(
            query: $query,
            tenantColumn: 'inventory_procurement_requests.tenant_id',
            facilityColumn: 'inventory_procurement_requests.facility_id',
        );
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
                static fn (InventoryProcurementRequestModel $request): array => $request->toArray(),
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

    private function baseQuery(): Builder
    {
        $query = InventoryProcurementRequestModel::query()
            ->leftJoin('inventory_items', 'inventory_items.id', '=', 'inventory_procurement_requests.item_id')
            ->leftJoin(
                'inventory_department_requisitions as source_department_requisitions',
                'source_department_requisitions.id',
                '=',
                'inventory_procurement_requests.source_department_requisition_id',
            )
            ->leftJoin(
                'inventory_department_requisition_lines as source_department_requisition_lines',
                'source_department_requisition_lines.id',
                '=',
                'inventory_procurement_requests.source_department_requisition_line_id',
            )
            ->select([
                'inventory_procurement_requests.*',
                'inventory_items.item_code as item_code',
                'inventory_items.item_name as item_name',
                'inventory_items.category as item_category',
                'inventory_items.unit as item_unit',
                'source_department_requisitions.requisition_number as source_department_requisition_number',
                'source_department_requisitions.requesting_department as source_department_name',
                'source_department_requisitions.status as source_department_requisition_status',
                'source_department_requisition_lines.requested_quantity as source_line_requested_quantity',
                'source_department_requisition_lines.approved_quantity as source_line_approved_quantity',
                'source_department_requisition_lines.issued_quantity as source_line_issued_quantity',
                'source_department_requisition_lines.unit as source_line_unit',
            ]);

        $this->applyPlatformScopeIfEnabled($query);

        return $query;
    }
}
