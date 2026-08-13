<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogRepositoryInterface;

class ListClinicalPrivilegeCatalogAuditLogsUseCase
{
    public function __construct(
        private readonly ClinicalPrivilegeCatalogRepositoryInterface $clinicalPrivilegeCatalogRepository,
        private readonly ClinicalPrivilegeCatalogAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    public function execute(string $privilegeCatalogId, array $filters): ?array
    {
        $catalog = $this->clinicalPrivilegeCatalogRepository->findById($privilegeCatalogId);
        if (! $catalog) {
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

        return $this->auditLogRepository->listByPrivilegeCatalogId(
            privilegeCatalogId: $privilegeCatalogId,
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
