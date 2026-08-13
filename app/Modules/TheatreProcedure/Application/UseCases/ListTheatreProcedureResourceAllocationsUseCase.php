<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceAllocationStatus;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceType;

class ListTheatreProcedureResourceAllocationsUseCase
{
    public function __construct(
        private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository,
        private readonly TheatreProcedureResourceAllocationRepositoryInterface $resourceAllocationRepository,
    ) {}

    public function execute(string $theatreProcedureId, array $filters): ?array
    {
        $procedure = $this->theatreProcedureRepository->findById($theatreProcedureId);
        if (! $procedure) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $resourceType = isset($filters['resourceType']) ? strtolower(trim((string) $filters['resourceType'])) : null;
        if (! in_array($resourceType, TheatreProcedureResourceType::values(), true)) {
            $resourceType = null;
        }

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        if (! in_array($status, TheatreProcedureResourceAllocationStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'resourceType' => 'resource_type',
            'resourceReference' => 'resource_reference',
            'plannedStartAt' => 'planned_start_at',
            'plannedEndAt' => 'planned_end_at',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'plannedStartAt';
        $sortBy = $sortMap[$sortBy] ?? 'planned_start_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->resourceAllocationRepository->searchByProcedure(
            theatreProcedureId: $theatreProcedureId,
            query: $query,
            resourceType: $resourceType,
            status: $status,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
