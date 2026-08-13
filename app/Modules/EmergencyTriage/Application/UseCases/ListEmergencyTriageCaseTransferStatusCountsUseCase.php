<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferPriority;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferType;

class ListEmergencyTriageCaseTransferStatusCountsUseCase
{
    public function __construct(
        private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository,
        private readonly EmergencyTriageCaseTransferRepositoryInterface $transferRepository,
    ) {}

    public function execute(string $emergencyTriageCaseId, array $filters): ?array
    {
        $case = $this->emergencyTriageCaseRepository->findById($emergencyTriageCaseId);
        if (! $case) {
            return null;
        }

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $transferType = isset($filters['transferType']) ? strtolower(trim((string) $filters['transferType'])) : null;
        if (! in_array($transferType, EmergencyTriageCaseTransferType::values(), true)) {
            $transferType = null;
        }

        $priority = isset($filters['priority']) ? strtolower(trim((string) $filters['priority'])) : null;
        if (! in_array($priority, EmergencyTriageCaseTransferPriority::values(), true)) {
            $priority = null;
        }

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->transferRepository->statusCountsByCase(
            emergencyTriageCaseId: $emergencyTriageCaseId,
            query: $query,
            transferType: $transferType,
            priority: $priority,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
