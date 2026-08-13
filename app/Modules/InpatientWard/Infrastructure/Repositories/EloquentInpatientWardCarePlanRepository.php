<?php

namespace App\Modules\InpatientWard\Infrastructure\Repositories;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCarePlanRepositoryInterface;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardCarePlanModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInpatientWardCarePlanRepository implements InpatientWardCarePlanRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $carePlan = new InpatientWardCarePlanModel();
        $carePlan->fill($attributes);
        $carePlan->save();

        return $carePlan->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InpatientWardCarePlanModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $carePlan = $query->find($id);

        return $carePlan?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InpatientWardCarePlanModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $carePlan = $query->find($id);
        if (! $carePlan) {
            return null;
        }

        $carePlan->fill($attributes);
        $carePlan->save();

        return $carePlan->toArray();
    }

    public function existsByCarePlanNumber(string $carePlanNumber): bool
    {
        return InpatientWardCarePlanModel::query()
            ->where('care_plan_number', $carePlanNumber)
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $admissionId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['care_plan_number', 'title', 'status', 'review_due_at', 'target_discharge_at', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'updated_at';

        $queryBuilder = InpatientWardCarePlanModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('care_plan_number', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhere('plan_text', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId))
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
        ?string $admissionId
    ): array {
        $queryBuilder = InpatientWardCarePlanModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('care_plan_number', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhere('plan_text', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'active' => 0,
            'completed' => 0,
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
                static fn (InpatientWardCarePlanModel $carePlan): array => $carePlan->toArray(),
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

