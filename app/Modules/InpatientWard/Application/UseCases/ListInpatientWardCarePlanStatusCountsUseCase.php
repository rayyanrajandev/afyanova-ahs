<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCarePlanRepositoryInterface;
use Illuminate\Support\Str;

class ListInpatientWardCarePlanStatusCountsUseCase
{
    public function __construct(private readonly InpatientWardCarePlanRepositoryInterface $carePlanRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' || ! Str::isUuid($admissionId) ? null : $admissionId;

        return $this->carePlanRepository->statusCounts(
            query: $query,
            admissionId: $admissionId,
        );
    }
}

