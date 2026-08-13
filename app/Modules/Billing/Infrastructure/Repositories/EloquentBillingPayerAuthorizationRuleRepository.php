<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingPayerAuthorizationRuleRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingPayerAuthorizationRuleModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentBillingPayerAuthorizationRuleRepository implements BillingPayerAuthorizationRuleRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $rule = new BillingPayerAuthorizationRuleModel();
        $rule->fill($attributes);
        $rule->save();

        return $rule->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = BillingPayerAuthorizationRuleModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $rule = $query->find($id);

        return $rule?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = BillingPayerAuthorizationRuleModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $rule = $query->find($id);
        if (! $rule) {
            return null;
        }

        $rule->fill($attributes);
        $rule->save();

        return $rule->toArray();
    }

    public function listByContractId(
        string $billingPayerContractId,
        ?string $status = null,
    ): array {
        $query = BillingPayerAuthorizationRuleModel::query()
            ->where('billing_payer_contract_id', $billingPayerContractId)
            ->when(
                $status !== null && trim($status) !== '',
                fn (Builder $builder) => $builder->where('status', trim($status)),
            )
            ->orderBy('service_type')
            ->orderBy('rule_name')
            ->orderBy('rule_code');

        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->get()
            ->map(static fn (BillingPayerAuthorizationRuleModel $rule): array => $rule->toArray())
            ->all();
    }

    public function existsByRuleCode(
        string $billingPayerContractId,
        string $ruleCode,
        ?string $excludeId = null
    ): bool {
        return BillingPayerAuthorizationRuleModel::query()
            ->where('billing_payer_contract_id', $billingPayerContractId)
            ->where('rule_code', $ruleCode)
            ->when(
                $excludeId !== null,
                fn (Builder $builder) => $builder->where('id', '!=', $excludeId),
            )
            ->exists();
    }

    public function searchByContractId(
        string $billingPayerContractId,
        ?string $query,
        ?string $status,
        ?string $serviceType,
        ?string $department,
        ?string $serviceCode,
        ?string $coverageDecision,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array(
            $sortBy,
            [
                'rule_code',
                'rule_name',
                'service_code',
                'service_type',
                'department',
                'priority',
                'amount_threshold',
                'coverage_decision',
                'coverage_percent_override',
                'requires_authorization',
                'auto_approve',
                'effective_from',
                'effective_to',
                'status',
                'updated_at',
                'created_at',
            ],
            true
        ) ? $sortBy : 'rule_name';

        $queryBuilder = BillingPayerAuthorizationRuleModel::query()
            ->where('billing_payer_contract_id', $billingPayerContractId);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('rule_code', 'like', $like)
                        ->orWhere('rule_name', 'like', $like)
                        ->orWhere('service_code', 'like', $like)
                        ->orWhere('service_type', 'like', $like)
                        ->orWhere('department', 'like', $like)
                        ->orWhere('diagnosis_code', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($serviceType, fn (Builder $builder, string $requestedServiceType) => $builder->where('service_type', $requestedServiceType))
            ->when($department, fn (Builder $builder, string $requestedDepartment) => $builder->where('department', $requestedDepartment))
            ->when($serviceCode, fn (Builder $builder, string $requestedServiceCode) => $builder->where('service_code', $requestedServiceCode))
            ->when($coverageDecision, fn (Builder $builder, string $requestedCoverageDecision) => $builder->where('coverage_decision', $requestedCoverageDecision))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function listActiveMatchingRulesByServiceCodes(
        string $billingPayerContractId,
        array $serviceCodes,
        ?string $asOfDateTime = null
    ): array {
        $normalizedCodes = array_values(array_unique(array_filter(
            array_map(static fn (mixed $code): string => strtoupper(trim((string) $code)), $serviceCodes),
            static fn (string $code): bool => $code !== '',
        )));

        if ($normalizedCodes === []) {
            return [];
        }

        $queryBuilder = BillingPayerAuthorizationRuleModel::query()
            ->where('billing_payer_contract_id', $billingPayerContractId)
            ->where('status', 'active')
            ->where(function (Builder $builder) use ($normalizedCodes): void {
                $builder->whereNull('service_code')
                    ->orWhereIn('service_code', $normalizedCodes);
            })
            ->when($asOfDateTime, function (Builder $builder, string $effectiveAt): void {
                $builder
                    ->where(function (Builder $nested) use ($effectiveAt): void {
                        $nested->whereNull('effective_from')
                            ->orWhere('effective_from', '<=', $effectiveAt);
                    })
                    ->where(function (Builder $nested) use ($effectiveAt): void {
                        $nested->whereNull('effective_to')
                            ->orWhere('effective_to', '>=', $effectiveAt);
                    });
            })
            ->orderByDesc('coverage_percent_override')
            ->orderByDesc('updated_at');

        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $rules = $queryBuilder
            ->get()
            ->map(static fn (BillingPayerAuthorizationRuleModel $rule): array => $rule->toArray())
            ->all();

        $map = [];
        foreach ($rules as $rule) {
            $code = strtoupper(trim((string) ($rule['service_code'] ?? '')));
            $map[$code][] = $rule;
        }

        return $map;
    }

    public function listActiveMatchingRules(
        string $billingPayerContractId,
        ?string $serviceCode,
        ?string $serviceType,
        ?string $department,
        ?string $asOfDateTime
    ): array {
        $serviceCode = $this->normalizeNullableUppercase($serviceCode);
        $serviceType = $this->normalizeNullableTrimmed($serviceType);
        $department = $this->normalizeNullableTrimmed($department);
        $asOfDateTime = $this->normalizeNullableTrimmed($asOfDateTime);

        $queryBuilder = BillingPayerAuthorizationRuleModel::query()
            ->where('billing_payer_contract_id', $billingPayerContractId)
            ->where('status', 'active')
            ->where(function (Builder $builder) use ($serviceCode): void {
                $builder->whereNull('service_code');
                if ($serviceCode !== null) {
                    $builder->orWhere('service_code', $serviceCode);
                }
            })
            ->where(function (Builder $builder) use ($serviceType): void {
                $builder->whereNull('service_type');
                if ($serviceType !== null) {
                    $builder->orWhere('service_type', $serviceType);
                }
            })
            ->where(function (Builder $builder) use ($department): void {
                $builder->whereNull('department');
                if ($department !== null) {
                    $builder->orWhere('department', $department);
                }
            })
            ->when($asOfDateTime, function (Builder $builder, string $effectiveAt): void {
                $builder
                    ->where(function (Builder $nested) use ($effectiveAt): void {
                        $nested->whereNull('effective_from')
                            ->orWhere('effective_from', '<=', $effectiveAt);
                    })
                    ->where(function (Builder $nested) use ($effectiveAt): void {
                        $nested->whereNull('effective_to')
                            ->orWhere('effective_to', '>=', $effectiveAt);
                    });
            })
            ->orderByDesc('coverage_percent_override')
            ->orderByDesc('updated_at');

        $this->applyPlatformScopeIfEnabled($queryBuilder);

        return $queryBuilder
            ->get()
            ->map(static fn (BillingPayerAuthorizationRuleModel $rule): array => $rule->toArray())
            ->all();
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
                static fn (BillingPayerAuthorizationRuleModel $rule): array => $rule->toArray(),
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

    private function normalizeNullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeNullableUppercase(?string $value): ?string
    {
        $normalized = $this->normalizeNullableTrimmed($value);

        return $normalized === null ? null : strtoupper($normalized);
    }
}
