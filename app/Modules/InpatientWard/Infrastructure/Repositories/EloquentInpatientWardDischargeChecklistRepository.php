<?php

namespace App\Modules\InpatientWard\Infrastructure\Repositories;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistRepositoryInterface;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardDischargeChecklistModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInpatientWardDischargeChecklistRepository implements InpatientWardDischargeChecklistRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $checklist = new InpatientWardDischargeChecklistModel();
        $checklist->fill($attributes);
        $checklist->save();

        return $checklist->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InpatientWardDischargeChecklistModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $checklist = $query->find($id);

        return $checklist?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InpatientWardDischargeChecklistModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $checklist = $query->find($id);
        if (! $checklist) {
            return null;
        }

        $checklist->fill($attributes);
        $checklist->save();

        return $checklist->toArray();
    }

    public function findByAdmissionId(string $admissionId): ?array
    {
        $query = InpatientWardDischargeChecklistModel::query()
            ->where('admission_id', $admissionId);
        $this->applyPlatformScopeIfEnabled($query);

        return $query->first()?->toArray();
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
        $sortBy = in_array($sortBy, ['status', 'reviewed_at', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'updated_at';

        $queryBuilder = InpatientWardDischargeChecklistModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('admission_id', 'like', $like)
                        ->orWhere('patient_id', 'like', $like)
                        ->orWhere('notes', 'like', $like)
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
        $queryBuilder = InpatientWardDischargeChecklistModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('admission_id', 'like', $like)
                        ->orWhere('patient_id', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId));

        $rows = (clone $queryBuilder)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $readyForDischargeCount = (clone $queryBuilder)
            ->where('is_ready_for_discharge', true)
            ->count();

        $counts = [
            'draft' => 0,
            'ready' => 0,
            'blocked' => 0,
            'completed' => 0,
            'readyForDischarge' => $readyForDischargeCount,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'other' && $status !== 'total' && $status !== 'readyfordischarge') {
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
                static fn (InpatientWardDischargeChecklistModel $checklist): array => $checklist->toArray(),
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


