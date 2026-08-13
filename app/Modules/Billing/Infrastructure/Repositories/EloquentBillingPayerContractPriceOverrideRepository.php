<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractPriceOverrideRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractPriceOverrideModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentBillingPayerContractPriceOverrideRepository implements BillingPayerContractPriceOverrideRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $override = new BillingPayerContractPriceOverrideModel();
        $override->fill($attributes);
        $override->save();

        return $override->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = BillingPayerContractPriceOverrideModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $override = $query->find($id);

        return $override?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = BillingPayerContractPriceOverrideModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $override = $query->find($id);
        if (! $override) {
            return null;
        }

        $override->fill($attributes);
        $override->save();

        return $override->toArray();
    }

    public function searchByContractId(
        string $billingPayerContractId,
        ?string $query,
        ?string $status,
        ?string $serviceType,
        ?string $pricingStrategy,
        ?string $serviceCode,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array(
            $sortBy,
            [
                'service_code',
                'service_name',
                'service_type',
                'department',
                'pricing_strategy',
                'override_value',
                'effective_from',
                'status',
                'updated_at',
                'created_at',
            ],
            true
        ) ? $sortBy : 'service_name';

        $queryBuilder = BillingPayerContractPriceOverrideModel::query()
            ->where('billing_payer_contract_id', $billingPayerContractId);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('service_code', 'like', $like)
                        ->orWhere('service_name', 'like', $like)
                        ->orWhere('service_type', 'like', $like)
                        ->orWhere('department', 'like', $like)
                        ->orWhere('pricing_strategy', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($serviceType, fn (Builder $builder, string $requestedServiceType) => $builder->where('service_type', $requestedServiceType))
            ->when($pricingStrategy, fn (Builder $builder, string $requestedPricingStrategy) => $builder->where('pricing_strategy', $requestedPricingStrategy))
            ->when($serviceCode, fn (Builder $builder, string $requestedServiceCode) => $builder->where('service_code', $requestedServiceCode))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function hasOverlappingWindow(
        string $billingPayerContractId,
        string $serviceCode,
        ?string $effectiveFrom,
        ?string $effectiveTo,
        ?string $excludeId = null
    ): bool {
        $query = BillingPayerContractPriceOverrideModel::query()
            ->where('billing_payer_contract_id', $billingPayerContractId)
            ->where('service_code', strtoupper(trim($serviceCode)))
            ->when(
                $excludeId !== null,
                fn (Builder $builder) => $builder->where('id', '!=', $excludeId),
            )
            ->where(function (Builder $builder) use ($effectiveTo): void {
                if ($effectiveTo === null) {
                    $builder->whereNull('effective_from')
                        ->orWhereNotNull('effective_from');

                    return;
                }

                $builder
                    ->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $effectiveTo);
            })
            ->where(function (Builder $builder) use ($effectiveFrom): void {
                if ($effectiveFrom === null) {
                    $builder->whereNull('effective_to')
                        ->orWhereNotNull('effective_to');

                    return;
                }

                $builder
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveFrom);
            });

        $this->applyPlatformScopeIfEnabled($query);

        return $query->exists();
    }

    public function findActiveApplicableOverride(
        string $billingPayerContractId,
        string $serviceCode,
        string $currencyCode,
        ?string $asOfDateTime = null
    ): ?array {
        $effectiveDateTime = $asOfDateTime ?? now()->toDateTimeString();

        $query = BillingPayerContractPriceOverrideModel::query()
            ->where('billing_payer_contract_id', $billingPayerContractId)
            ->where('service_code', strtoupper(trim($serviceCode)))
            ->where('currency_code', strtoupper(trim($currencyCode)))
            ->where('status', 'active')
            ->where(function (Builder $builder) use ($effectiveDateTime): void {
                $builder->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $effectiveDateTime);
            })
            ->where(function (Builder $builder) use ($effectiveDateTime): void {
                $builder->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveDateTime);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('updated_at');

        $this->applyPlatformScopeIfEnabled($query);

        $override = $query->first();

        return $override?->toArray();
    }

    public function findActiveApplicableOverrides(
        string $billingPayerContractId,
        array $serviceCodes,
        string $currencyCode,
        ?string $asOfDateTime = null
    ): array {
        $normalizedCodes = array_values(array_unique(array_filter(
            array_map(static fn (mixed $code): string => strtoupper(trim((string) $code)), $serviceCodes),
            static fn (string $code): bool => $code !== '',
        )));

        if ($normalizedCodes === []) {
            return [];
        }

        $effectiveDateTime = $asOfDateTime ?? now()->toDateTimeString();

        $query = BillingPayerContractPriceOverrideModel::query()
            ->where('billing_payer_contract_id', $billingPayerContractId)
            ->whereIn('service_code', $normalizedCodes)
            ->where('currency_code', strtoupper(trim($currencyCode)))
            ->where('status', 'active')
            ->where(function (Builder $builder) use ($effectiveDateTime): void {
                $builder->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $effectiveDateTime);
            })
            ->where(function (Builder $builder) use ($effectiveDateTime): void {
                $builder->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveDateTime);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('updated_at');

        $this->applyPlatformScopeIfEnabled($query);

        $map = [];
        foreach ($query->get() as $override) {
            $code = strtoupper(trim((string) $override->service_code));
            if ($code !== '' && ! isset($map[$code])) {
                $map[$code] = $override->toArray();
            }
        }

        return $map;
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
                static fn (BillingPayerContractPriceOverrideModel $override): array => $override->toArray(),
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
