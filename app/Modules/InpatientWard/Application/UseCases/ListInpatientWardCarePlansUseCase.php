<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCarePlanRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardCarePlanStatus;
use Illuminate\Support\Str;

class ListInpatientWardCarePlansUseCase
{
    public function __construct(private readonly InpatientWardCarePlanRepositoryInterface $carePlanRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, InpatientWardCarePlanStatus::values(), true)) {
            $status = null;
        }

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' || ! Str::isUuid($admissionId) ? null : $admissionId;

        $sortMap = [
            'carePlanNumber' => 'care_plan_number',
            'title' => 'title',
            'status' => 'status',
            'reviewDueAt' => 'review_due_at',
            'targetDischargeAt' => 'target_discharge_at',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'updatedAt';
        $sortBy = $sortMap[$sortBy] ?? 'updated_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        return $this->carePlanRepository->search(
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

