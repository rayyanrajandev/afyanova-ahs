<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventorySupplierModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventorySupplierRepository implements InventorySupplierRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $supplier = new InventorySupplierModel();
        $supplier->fill($attributes);
        $supplier->save();

        return $supplier->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventorySupplierModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $supplier = $query->find($id);

        return $supplier?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventorySupplierModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $supplier = $query->find($id);
        if (! $supplier) {
            return null;
        }

        $supplier->fill($attributes);
        $supplier->save();

        return $supplier->toArray();
    }

    public function existsBySupplierCodeInScope(
        string $supplierCode,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool {
        $query = InventorySupplierModel::query()
            ->whereRaw('LOWER(supplier_code) = ?', [strtolower(trim($supplierCode))]);

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        if ($facilityId === null) {
            $query->whereNull('facility_id');
        } else {
            $query->where('facility_id', $facilityId);
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $countryCode,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['supplier_code', 'supplier_name', 'country_code', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'supplier_name';

        $queryBuilder = InventorySupplierModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('supplier_code', 'like', $like)
                        ->orWhere('supplier_name', 'like', $like)
                        ->orWhere('tin_number', 'like', $like)
                        ->orWhere('bank_account_number', 'like', $like)
                        ->orWhere('lipa_number', 'like', $like)
                        ->orWhere('contact_person', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($countryCode, fn (Builder $builder, string $value) => $builder->where('country_code', strtoupper($value)))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCounts(?string $query, ?string $countryCode): array
    {
        $queryBuilder = InventorySupplierModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('supplier_code', 'like', $like)
                        ->orWhere('supplier_name', 'like', $like)
                        ->orWhere('tin_number', 'like', $like)
                        ->orWhere('bank_account_number', 'like', $like)
                        ->orWhere('lipa_number', 'like', $like)
                        ->orWhere('contact_person', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($countryCode, fn (Builder $builder, string $value) => $builder->where('country_code', strtoupper($value)));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'active' => 0,
            'inactive' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'total' && $status !== 'other') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
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
                static fn (InventorySupplierModel $supplier): array => $supplier->toArray(),
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
