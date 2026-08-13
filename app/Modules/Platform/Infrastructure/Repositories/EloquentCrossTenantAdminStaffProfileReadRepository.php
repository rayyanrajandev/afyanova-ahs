<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\CrossTenantAdminStaffProfileReadRepositoryInterface;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCrossTenantAdminStaffProfileReadRepository implements CrossTenantAdminStaffProfileReadRepositoryInterface
{
    public function searchByTenantId(
        string $tenantId,
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $employmentType,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['employee_number', 'department', 'job_title', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'employee_number';

        $queryBuilder = StaffProfileModel::query()
            ->where('tenant_id', $tenantId)
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('employee_number', 'like', $like)
                        ->orWhere('department', 'like', $like)
                        ->orWhere('job_title', 'like', $like)
                        ->orWhere('professional_license_number', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($department, fn (Builder $builder, string $requestedDepartment) => $builder->where('department', $requestedDepartment))
            ->when($employmentType, fn (Builder $builder, string $requestedEmploymentType) => $builder->where('employment_type', $requestedEmploymentType))
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
                static fn (StaffProfileModel $profile): array => $profile->toArray(),
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
