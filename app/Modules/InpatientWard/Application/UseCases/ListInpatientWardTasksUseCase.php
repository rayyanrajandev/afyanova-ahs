<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardTaskRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardTaskPriority;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardTaskStatus;
use Illuminate\Support\Str;

class ListInpatientWardTasksUseCase
{
    public function __construct(private readonly InpatientWardTaskRepositoryInterface $taskRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, InpatientWardTaskStatus::values(), true)) {
            $status = null;
        }

        $priority = isset($filters['priority']) ? trim((string) $filters['priority']) : null;
        if (! in_array($priority, InpatientWardTaskPriority::values(), true)) {
            $priority = null;
        }

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' || ! Str::isUuid($admissionId) ? null : $admissionId;

        $sortMap = [
            'taskNumber' => 'task_number',
            'priority' => 'priority',
            'status' => 'status',
            'dueAt' => 'due_at',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'createdAt';
        $sortBy = $sortMap[$sortBy] ?? 'created_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        return $this->taskRepository->search(
            query: $query,
            status: $status,
            priority: $priority,
            admissionId: $admissionId,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
