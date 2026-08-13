<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\MedicalRecord\Infrastructure\Models\MedicalRecordModel;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminMedicalRecordReadRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCrossTenantAdminMedicalRecordReadRepository implements CrossTenantAdminMedicalRecordReadRepositoryInterface
{
    public function searchByTenantId(
        string $tenantId,
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $recordType,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['record_number', 'encounter_at', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'encounter_at';

        $queryBuilder = MedicalRecordModel::query()
            ->where('tenant_id', $tenantId)
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';

                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('record_number', 'like', $like)
                        ->orWhere('record_type', 'like', $like)
                        ->orWhere('assessment', 'like', $like)
                        ->orWhere('plan', 'like', $like)
                        ->orWhere('diagnosis_code', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($recordType, fn (Builder $builder, string $requestedRecordType) => $builder->where('record_type', $requestedRecordType))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('encounter_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('encounter_at', '<=', $endDateTime))
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
                static fn (MedicalRecordModel $record): array => $record->toArray(),
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
