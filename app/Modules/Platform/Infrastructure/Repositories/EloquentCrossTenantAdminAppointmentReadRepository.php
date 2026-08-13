<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAppointmentReadRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCrossTenantAdminAppointmentReadRepository implements CrossTenantAdminAppointmentReadRepositoryInterface
{
    public function searchByTenantId(
        string $tenantId,
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['appointment_number', 'scheduled_at', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'scheduled_at';

        $queryBuilder = AppointmentModel::query()
            ->where('tenant_id', $tenantId)
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('appointment_number', 'like', $like)
                        ->orWhere('reason', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('scheduled_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('scheduled_at', '<=', $endDateTime))
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
                static fn (AppointmentModel $appointment): array => $appointment->toArray(),
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
