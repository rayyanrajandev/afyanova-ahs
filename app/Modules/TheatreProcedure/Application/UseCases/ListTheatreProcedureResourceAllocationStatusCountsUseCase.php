<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceType;

class ListTheatreProcedureResourceAllocationStatusCountsUseCase
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

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $resourceType = isset($filters['resourceType']) ? strtolower(trim((string) $filters['resourceType'])) : null;
        if (! in_array($resourceType, TheatreProcedureResourceType::values(), true)) {
            $resourceType = null;
        }

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->resourceAllocationRepository->statusCountsByProcedure(
            theatreProcedureId: $theatreProcedureId,
            query: $query,
            resourceType: $resourceType,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
