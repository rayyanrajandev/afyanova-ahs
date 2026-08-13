<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseTransferRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferPriority;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferStatus;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferType;

class ListEmergencyTriageCaseTransfersUseCase
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

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $transferType = isset($filters['transferType']) ? strtolower(trim((string) $filters['transferType'])) : null;
        if (! in_array($transferType, EmergencyTriageCaseTransferType::values(), true)) {
            $transferType = null;
        }

        $priority = isset($filters['priority']) ? strtolower(trim((string) $filters['priority'])) : null;
        if (! in_array($priority, EmergencyTriageCaseTransferPriority::values(), true)) {
            $priority = null;
        }

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        if (! in_array($status, EmergencyTriageCaseTransferStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'transferNumber' => 'transfer_number',
            'transferType' => 'transfer_type',
            'priority' => 'priority',
            'destinationLocation' => 'destination_location',
            'requestedAt' => 'requested_at',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'requestedAt';
        $sortBy = $sortMap[$sortBy] ?? 'requested_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->transferRepository->searchByCase(
            emergencyTriageCaseId: $emergencyTriageCaseId,
            query: $query,
            transferType: $transferType,
            priority: $priority,
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
