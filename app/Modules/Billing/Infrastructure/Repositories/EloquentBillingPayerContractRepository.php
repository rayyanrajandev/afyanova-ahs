<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentBillingPayerContractRepository implements BillingPayerContractRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $contract = new BillingPayerContractModel();
        $contract->fill($attributes);
        $contract->save();

        return $contract->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = BillingPayerContractModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $contract = $query->find($id);

        return $contract?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = BillingPayerContractModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $contract = $query->find($id);
        if (! $contract) {
            return null;
        }

        $contract->fill($attributes);
        $contract->save();

        return $contract->toArray();
    }

    public function existsByContractCode(
        string $contractCode,
        ?string $tenantId = null,
        ?string $facilityId = null,
        ?string $excludeId = null
    ): bool {
        return BillingPayerContractModel::query()
            ->where('contract_code', $contractCode)
            ->when(
                $tenantId !== null,
                fn (Builder $builder) => $builder->where('tenant_id', $tenantId),
            )
            ->when(
                $facilityId !== null,
                fn (Builder $builder) => $builder->where('facility_id', $facilityId),
            )
            ->when(
                $excludeId !== null,
                fn (Builder $builder) => $builder->where('id', '!=', $excludeId),
            )
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $payerType,
        ?string $status,
        ?string $currencyCode,
        ?bool $requiresPreAuthorization,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array(
            $sortBy,
            [
                'contract_code',
                'contract_name',
                'payer_type',
                'payer_name',
                'payer_plan_code',
                'currency_code',
                'requires_pre_authorization',
                'status',
                'effective_from',
                'updated_at',
                'created_at',
            ],
            true
        ) ? $sortBy : 'contract_name';

        $queryBuilder = BillingPayerContractModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('contract_code', 'like', $like)
                        ->orWhere('contract_name', 'like', $like)
                        ->orWhere('payer_name', 'like', $like)
                        ->orWhere('payer_plan_code', 'like', $like)
                        ->orWhere('payer_plan_name', 'like', $like);
                });
            })
            ->when($payerType, fn (Builder $builder, string $requestedPayerType) => $builder->where('payer_type', $requestedPayerType))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($currencyCode, fn (Builder $builder, string $requestedCurrencyCode) => $builder->where('currency_code', $requestedCurrencyCode))
            ->when(
                $requiresPreAuthorization !== null,
                fn (Builder $builder) => $builder->where('requires_pre_authorization', $requiresPreAuthorization),
            )
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
        ?string $payerType,
        ?string $currencyCode,
        ?bool $requiresPreAuthorization
    ): array {
        $queryBuilder = BillingPayerContractModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('contract_code', 'like', $like)
                        ->orWhere('contract_name', 'like', $like)
                        ->orWhere('payer_name', 'like', $like)
                        ->orWhere('payer_plan_code', 'like', $like)
                        ->orWhere('payer_plan_name', 'like', $like);
                });
            })
            ->when($payerType, fn (Builder $builder, string $requestedPayerType) => $builder->where('payer_type', $requestedPayerType))
            ->when($currencyCode, fn (Builder $builder, string $requestedCurrencyCode) => $builder->where('currency_code', $requestedCurrencyCode))
            ->when(
                $requiresPreAuthorization !== null,
                fn (Builder $builder) => $builder->where('requires_pre_authorization', $requiresPreAuthorization),
            );

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'active' => 0,
            'inactive' => 0,
            'retired' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $rowStatus = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($rowStatus, $counts) && $rowStatus !== 'other' && $rowStatus !== 'total') {
                $counts[$rowStatus] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    public function findActiveContractByProvider(
        string $payerName,
        string $tenantId,
        string $facilityId
    ): ?array {
        $query = BillingPayerContractModel::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('payer_name', $payerName)
            ->where('status', 'active')
            ->where('effective_from', '<=', now())
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->latest('effective_from');

        $this->applyPlatformScopeIfEnabled($query);

        return $query->first()?->toArray();
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
                static fn (BillingPayerContractModel $contract): array => $contract->toArray(),
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
