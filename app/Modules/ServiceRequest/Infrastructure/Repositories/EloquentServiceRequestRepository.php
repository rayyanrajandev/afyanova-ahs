<?php

namespace App\Modules\ServiceRequest\Infrastructure\Repositories;

use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;
use App\Modules\ServiceRequest\Infrastructure\Models\ServiceRequestModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentServiceRequestRepository implements ServiceRequestRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $model = new ServiceRequestModel();
        $model->fill($attributes);
        $model->save();
        $model->load($this->departmentRelation());
        $model->load('items');

        return $model->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = ServiceRequestModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->with($this->departmentRelation())
            ->with(['items'])
            ->find($id)
            ?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = ServiceRequestModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $model = $query->find($id);
        if (! $model) {
            return null;
        }

        $model->fill($attributes);
        $model->save();
        $model->load($this->departmentRelation());
        $model->load(['items']);

        return $model->toArray();
    }

    public function existsByRequestNumber(string $requestNumber): bool
    {
        return ServiceRequestModel::query()
            ->where('request_number', $requestNumber)
            ->exists();
    }

    public function findActiveForPatientAndServiceType(string $patientId, string $serviceType): ?array
    {
        $queryBuilder = ServiceRequestModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        return $queryBuilder
            ->with($this->departmentRelation())
            ->with(['items'])
            ->where('patient_id', $patientId)
            ->where('service_type', $serviceType)
            ->whereIn('status', ['pending', 'in_progress'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('requested_at')
            ->first()
            ?->toArray();
    }

    public function search(
        ?string $patientId,
        ?string $serviceType,
        ?string $status,
        ?string $priority,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        string $sortDirection,
        ?string $departmentId = null,
    ): array {
        $queryBuilder = ServiceRequestModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->with($this->departmentRelation())
            ->with(['items'])
            ->when($patientId, fn (Builder $b, string $v) => $b->where('patient_id', $v))
            ->when($serviceType, fn (Builder $b, string $v) => $b->where('service_type', $v))
            ->when($status, fn (Builder $b, string $v) => $b->where('status', $v))
            ->when($priority, fn (Builder $b, string $v) => $b->where('priority', $v))
            ->when($departmentId, fn (Builder $b, string $v) => $b->where('department_id', $v))
            ->when($fromDateTime, fn (Builder $b, string $v) => $b->where('requested_at', '>=', $v))
            ->when($toDateTime, fn (Builder $b, string $v) => $b->where('requested_at', '<=', $v))
            ->orderBy('requested_at', $sortDirection === 'asc' ? 'asc' : 'desc');

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCounts(
        ?string $serviceType,
        ?string $priority,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $departmentId = null,
    ): array {
        $queryBuilder = ServiceRequestModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($serviceType, fn (Builder $b, string $v) => $b->where('service_type', $v))
            ->when($priority, fn (Builder $b, string $v) => $b->where('priority', $v))
            ->when($departmentId, fn (Builder $b, string $v) => $b->where('department_id', $v))
            ->when($fromDateTime, fn (Builder $b, string $v) => $b->where('requested_at', '>=', $v))
            ->when($toDateTime, fn (Builder $b, string $v) => $b->where('requested_at', '<=', $v));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'pending' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'total') {
                $counts[$status] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    public function findActiveByPatientIds(array $patientIds): array
    {
        $patientIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => is_string($id) ? trim($id) : '',
            $patientIds,
        ), static fn (string $id): bool => $id !== '')));

        if ($patientIds === []) {
            return [];
        }

        $queryBuilder = ServiceRequestModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $models = $queryBuilder
            ->with($this->departmentRelation())
            ->with(['items'])
            ->whereIn('patient_id', $patientIds)
            ->whereIn('status', ['pending', 'in_progress'])
            ->orderByRaw("CASE WHEN priority = 'urgent' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('requested_at')
            ->get();

        /** @var array<string, array<int, array<string, mixed>>> */
        $grouped = [];

        foreach ($models as $model) {
            $patientId = (string) $model->patient_id;
            $grouped[$patientId][] = $model->toArray();
        }

        return $grouped;
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

    /**
     * @return array<int, string>
     */
    private function departmentRelation(): array
    {
        return ['department:id,code,name,service_type'];
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (ServiceRequestModel $m): array => $m->toArray(),
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
