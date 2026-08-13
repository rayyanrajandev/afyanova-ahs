<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAdmissionReadRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCrossTenantAdminAdmissionReadRepository implements CrossTenantAdminAdmissionReadRepositoryInterface
{
    public function searchByTenantId(
        string $tenantId,
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $ward,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['admission_number', 'admitted_at', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'admitted_at';

        $queryBuilder = AdmissionModel::query()
            ->where('tenant_id', $tenantId)
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('admission_number', 'like', $like)
                        ->orWhere('admission_reason', 'like', $like)
                        ->orWhere('ward', 'like', $like)
                        ->orWhere('bed', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($ward, fn (Builder $builder, string $requestedWard) => $builder->where('ward', $requestedWard))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('admitted_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('admitted_at', '<=', $endDateTime))
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
                static fn (AdmissionModel $admission): array => $admission->toArray(),
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
