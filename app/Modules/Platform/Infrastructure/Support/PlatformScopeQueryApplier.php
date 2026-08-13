<?php

namespace App\Modules\Platform\Infrastructure\Support;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;

class PlatformScopeQueryApplier
{
    public function __construct(private readonly CurrentPlatformScopeContextInterface $scopeContext) {}

    /**
     * Apply current request platform scope to a query.
     *
     * Facility scope takes precedence over tenant scope when both exist.
     *
     * @template T of EloquentBuilder|QueryBuilder
     * @param  T  $query
     * @return T
     */
    public function apply(
        EloquentBuilder|QueryBuilder $query,
        ?string $tenantColumn = 'tenant_id',
        ?string $facilityColumn = 'facility_id',
        bool $requireResolvedScope = false,
    ): EloquentBuilder|QueryBuilder {
        $facilityId = $this->scopeContext->facilityId();
        if ($facilityId !== null && $facilityColumn !== null) {
            $query->where($facilityColumn, $facilityId);

            return $query;
        }

        $tenantId = $this->scopeContext->tenantId();
        if ($tenantId !== null && $tenantColumn !== null) {
            $query->where($tenantColumn, $tenantId);

            return $query;
        }

        if ($requireResolvedScope) {
            throw new RuntimeException('Platform scope is required but unresolved.');
        }

        return $query;
    }

    /**
     * @template T of EloquentBuilder|QueryBuilder
     * @param  T  $query
     * @return T
     */
    public function requireFacility(
        EloquentBuilder|QueryBuilder $query,
        string $facilityColumn = 'facility_id',
    ): EloquentBuilder|QueryBuilder {
        $facilityId = $this->scopeContext->facilityId();
        if ($facilityId === null) {
            throw new RuntimeException('Facility scope is required but unresolved.');
        }

        $query->where($facilityColumn, $facilityId);

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function currentScope(): array
    {
        return $this->scopeContext->toArray();
    }
}
