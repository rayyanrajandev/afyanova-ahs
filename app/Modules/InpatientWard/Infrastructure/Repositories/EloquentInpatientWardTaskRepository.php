<?php

namespace App\Modules\InpatientWard\Infrastructure\Repositories;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardTaskRepositoryInterface;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardTaskModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInpatientWardTaskRepository implements InpatientWardTaskRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $task = new InpatientWardTaskModel();
        $task->fill($attributes);
        $task->save();

        return $task->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InpatientWardTaskModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $task = $query->find($id);

        return $task?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InpatientWardTaskModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $task = $query->find($id);
        if (! $task) {
            return null;
        }

        $task->fill($attributes);
        $task->save();

        return $task->toArray();
    }

    public function existsByTaskNumber(string $taskNumber): bool
    {
        return InpatientWardTaskModel::query()
            ->where('task_number', $taskNumber)
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $priority,
        ?string $admissionId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['task_number', 'priority', 'status', 'due_at', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'created_at';

        $queryBuilder = InpatientWardTaskModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('task_number', 'like', $like)
                        ->orWhere('task_type', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($priority, fn (Builder $builder, string $requestedPriority) => $builder->where('priority', $requestedPriority))
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
        ?string $priority,
        ?string $admissionId
    ): array {
        $queryBuilder = InpatientWardTaskModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('task_number', 'like', $like)
                        ->orWhere('task_type', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($priority, fn (Builder $builder, string $requestedPriority) => $builder->where('priority', $requestedPriority))
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'pending' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'escalated' => 0,
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
                static fn (InpatientWardTaskModel $task): array => $task->toArray(),
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
