<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardDischargeChecklistStatus;
use Illuminate\Support\Str;

class ListInpatientWardDischargeChecklistsUseCase
{
    public function __construct(private readonly InpatientWardDischargeChecklistRepositoryInterface $checklistRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, InpatientWardDischargeChecklistStatus::values(), true)) {
            $status = null;
        }

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' || ! Str::isUuid($admissionId) ? null : $admissionId;

        $sortMap = [
            'status' => 'status',
            'reviewedAt' => 'reviewed_at',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'updatedAt';
        $sortBy = $sortMap[$sortBy] ?? 'updated_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        return $this->checklistRepository->search(
            query: $query,
            status: $status,
            admissionId: $admissionId,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}

