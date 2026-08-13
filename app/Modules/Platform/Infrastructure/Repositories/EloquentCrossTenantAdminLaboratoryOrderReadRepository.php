<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminLaboratoryOrderReadRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCrossTenantAdminLaboratoryOrderReadRepository implements CrossTenantAdminLaboratoryOrderReadRepositoryInterface
{
    public function searchByTenantId(
        string $tenantId,
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $priority,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['order_number', 'ordered_at', 'status', 'priority', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'ordered_at';

        $queryBuilder = LaboratoryOrderModel::query()
            ->where('tenant_id', $tenantId)
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('order_number', 'like', $like)
                        ->orWhere('test_code', 'like', $like)
                        ->orWhere('test_name', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($priority, fn (Builder $builder, string $requestedPriority) => $builder->where('priority', $requestedPriority))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('ordered_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('ordered_at', '<=', $endDateTime))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (LaboratoryOrderModel $order): array => $order->toArray(),
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
