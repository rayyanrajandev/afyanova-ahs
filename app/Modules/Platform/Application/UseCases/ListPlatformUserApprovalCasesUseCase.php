<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformUserApprovalCaseRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserApprovalCaseActionType;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserApprovalCaseStatus;

class ListPlatformUserApprovalCasesUseCase
{
    public function __construct(private readonly PlatformUserApprovalCaseRepositoryInterface $platformUserApprovalCaseRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, PlatformUserApprovalCaseStatus::values(), true)) {
            $status = null;
        }

        $actionType = isset($filters['actionType']) ? trim((string) $filters['actionType']) : null;
        if (! in_array($actionType, PlatformUserApprovalCaseActionType::values(), true)) {
            $actionType = null;
        }

        $targetUserId = $this->nullableIntegerValue($filters['targetUserId'] ?? null);
        $requesterUserId = $this->nullableIntegerValue($filters['requesterUserId'] ?? null);
        $reviewerUserId = $this->nullableIntegerValue($filters['reviewerUserId'] ?? null);

        $fromDateTime = isset($filters['fromDate']) ? trim((string) $filters['fromDate']) : (isset($filters['from']) ? trim((string) $filters['from']) : null);
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['toDate']) ? trim((string) $filters['toDate']) : (isset($filters['to']) ? trim((string) $filters['to']) : null);
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        $sortMap = [
            'caseReference' => 'case_reference',
            'status' => 'status',
            'actionType' => 'action_type',
            'submittedAt' => 'submitted_at',
            'decidedAt' => 'decided_at',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'createdAt'] ?? 'created_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        return $this->platformUserApprovalCaseRepository->searchCases(
            query: $query,
            status: $status,
            actionType: $actionType,
            targetUserId: $targetUserId,
            requesterUserId: $requesterUserId,
            reviewerUserId: $reviewerUserId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }

    private function nullableIntegerValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        $resolved = (int) $normalized;

        return $resolved > 0 ? $resolved : null;
    }
}

