<?php

namespace App\Modules\TheatreProcedure\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceAllocationStatus;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureResourceAllocationModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentTheatreProcedureResourceAllocationRepository implements TheatreProcedureResourceAllocationRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $allocation = new TheatreProcedureResourceAllocationModel();
        $allocation->fill($attributes);
        $allocation->save();

        return $allocation->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = TheatreProcedureResourceAllocationModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $allocation = $query->find($id);

        return $allocation?->toArray();
    }

    public function findByProcedureAndId(string $theatreProcedureId, string $id): ?array
    {
        $query = TheatreProcedureResourceAllocationModel::query()
            ->where('theatre_procedure_id', $theatreProcedureId);
        $this->applyPlatformScopeIfEnabled($query);
        $allocation = $query->where('id', $id)->first();

        return $allocation?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = TheatreProcedureResourceAllocationModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $allocation = $query->find($id);
        if (! $allocation) {
            return null;
        }

        $allocation->fill($attributes);
        $allocation->save();

        return $allocation->toArray();
    }

    public function searchByProcedure(
        string $theatreProcedureId,
        ?string $query,
        ?string $resourceType,
        ?string $status,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['resource_type', 'resource_reference', 'planned_start_at', 'planned_end_at', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'planned_start_at';

        $queryBuilder = TheatreProcedureResourceAllocationModel::query()
            ->where('theatre_procedure_id', $theatreProcedureId);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('resource_reference', 'like', $like)
                        ->orWhere('role_label', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($resourceType, fn (Builder $builder, string $value) => $builder->where('resource_type', $value))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('planned_start_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('planned_start_at', '<=', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCountsByProcedure(
        string $theatreProcedureId,
        ?string $query,
        ?string $resourceType,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = TheatreProcedureResourceAllocationModel::query()
            ->where('theatre_procedure_id', $theatreProcedureId);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('resource_reference', 'like', $like)
                        ->orWhere('role_label', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($resourceType, fn (Builder $builder, string $value) => $builder->where('resource_type', $value))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('planned_start_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('planned_start_at', '<=', $value));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'scheduled' => 0,
            'in_use' => 0,
            'released' => 0,
            'cancelled' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'other' && $status !== 'total') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    public function hasOverlapForResource(
        string $resourceType,
        string $resourceReference,
        string $plannedStartAt,
        string $plannedEndAt,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeAllocationId = null
    ): bool {
        $normalizedStart = $this->normalizeDateTimeForQuery($plannedStartAt);
        $normalizedEnd = $this->normalizeDateTimeForQuery($plannedEndAt);

        $query = TheatreProcedureResourceAllocationModel::query()
            ->where('resource_type', $resourceType)
            ->where('resource_reference', $resourceReference)
            ->whereIn('status', TheatreProcedureResourceAllocationStatus::overlapBlockingValues())
            ->where('planned_start_at', '<', $normalizedEnd)
            ->where('planned_end_at', '>', $normalizedStart)
            ->when(
                $excludeAllocationId !== null && $excludeAllocationId !== '',
                fn (Builder $builder) => $builder->where('id', '!=', $excludeAllocationId),
            );

        $this->applyPlatformScopeIfEnabled($query);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        } else {
            $query->whereNull('tenant_id');
        }

        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        } else {
            $query->whereNull('facility_id');
        }

        return $query->exists();
    }

    private function normalizeDateTimeForQuery(string $value): string
    {
        try {
            return CarbonImmutable::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return $value;
        }
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
                static fn (TheatreProcedureResourceAllocationModel $allocation): array => $allocation->toArray(),
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
