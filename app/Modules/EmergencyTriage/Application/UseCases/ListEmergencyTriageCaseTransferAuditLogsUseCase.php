<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferAuditLogRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferRepositoryInterface;

class ListEmergencyTriageCaseTransferAuditLogsUseCase
{
    public function __construct(
        private readonly EmergencyTriageCaseTransferRepositoryInterface $transferRepository,
        private readonly EmergencyTriageCaseTransferAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    public function execute(string $emergencyTriageCaseId, string $transferId, array $filters): ?array
    {
        $transfer = $this->transferRepository->findByCaseAndId($emergencyTriageCaseId, $transferId);
        if (! $transfer) {
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

        return $this->auditLogRepository->listByTransferId(
            transferId: $transferId,
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
