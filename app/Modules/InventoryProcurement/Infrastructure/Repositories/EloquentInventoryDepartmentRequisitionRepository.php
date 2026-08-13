<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventoryDepartmentRequisitionRepository implements InventoryDepartmentRequisitionRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $model = new InventoryDepartmentRequisitionModel();
        $model->fill($attributes);
        $model->save();

        return $model->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventoryDepartmentRequisitionModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query->find($id)?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventoryDepartmentRequisitionModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $model = $query->find($id);
        if (! $model) {
            return null;
        }

        $model->fill($attributes);
        $model->save();

        return $model->toArray();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $departmentId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['requisition_number', 'requesting_department', 'priority', 'status', 'needed_by', 'created_at'], true)
            ? $sortBy
            : 'created_at';

        $queryBuilder = InventoryDepartmentRequisitionModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $q) use ($like): void {
                    $q->where('requisition_number', 'like', $like)
                        ->orWhere('requesting_department', 'like', $like);
                });
            })
            ->when($status, fn (Builder $b, string $s) => $b->where('status', $s))
            ->when($departmentId, fn (Builder $b, string $id) => $b->where('requesting_department_id', $id))
            ->when($department, fn (Builder $b, string $d) => $b->where('requesting_department', $d))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(perPage: $perPage, page: $page);

        return $this->toSearchResult($paginator);
    }

    public function nextRequisitionNumber(): string
    {
        $prefix = 'REQ-'.now()->format('Ymd').'-';
        $latest = InventoryDepartmentRequisitionModel::query()
            ->where('requisition_number', 'like', $prefix.'%')
            ->orderByDesc('requisition_number')
            ->value('requisition_number');

        if ($latest === null) {
            return $prefix.'0001';
        }

        $sequence = (int) str_replace($prefix, '', $latest);

        return $prefix.str_pad((string) ($sequence + 1), 4, '0', STR_PAD_LEFT);
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
                static fn ($model) => $model->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
