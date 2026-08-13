<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityResourceAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;

class ListFacilityResourceAuditLogsUseCase
{
    public function __construct(
        private readonly FacilityResourceRepositoryInterface $facilityResourceRepository,
        private readonly FacilityResourceAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    public function execute(string $resourceId, string $resourceType, array $filters): ?array
    {
        $resource = $this->facilityResourceRepository->findById($resourceId);
        if (! $resource || ($resource['resource_type'] ?? null) !== $resourceType) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;
        $action = isset($filters['action']) ? trim((string) $filters['action']) : null;
        $action = $action === '' ? null : $action;
        $actorType = isset($filters['actorType']) ? strtolower(trim((string) $filters['actorType'])) : null;
        $actorType = in_array($actorType, ['system', 'user'], true) ? $actorType : null;

        $actorIdInput = isset($filters['actorId']) ? trim((string) $filters['actorId']) : null;
        $actorIdInput = $actorIdInput === '' ? null : $actorIdInput;
        $actorId = $actorIdInput !== null && ctype_digit($actorIdInput)
            ? (int) $actorIdInput
            : null;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->auditLogRepository->listByResourceId(
            facilityResourceId: $resourceId,
            page: $page,
            perPage: $perPage,
            query: $query,
            action: $action,
            actorType: $actorType,
            actorId: $actorId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}

