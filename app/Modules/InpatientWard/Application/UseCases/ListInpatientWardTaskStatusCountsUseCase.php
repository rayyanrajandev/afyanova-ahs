<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardTaskRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardTaskPriority;
use Illuminate\Support\Str;

class ListInpatientWardTaskStatusCountsUseCase
{
    public function __construct(private readonly InpatientWardTaskRepositoryInterface $taskRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $priority = isset($filters['priority']) ? trim((string) $filters['priority']) : null;
        if (! in_array($priority, InpatientWardTaskPriority::values(), true)) {
            $priority = null;
        }

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' || ! Str::isUuid($admissionId) ? null : $admissionId;

        return $this->taskRepository->statusCounts(
            query: $query,
            priority: $priority,
            admissionId: $admissionId,
        );
    }
}
