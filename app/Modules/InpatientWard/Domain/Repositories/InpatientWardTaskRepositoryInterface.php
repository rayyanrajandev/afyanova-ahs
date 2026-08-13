<?php

namespace App\Modules\InpatientWard\Domain\Repositories;

interface InpatientWardTaskRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByTaskNumber(string $taskNumber): bool;

    public function search(
        ?string $query,
        ?string $status,
        ?string $priority,
        ?string $admissionId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $priority,
        ?string $admissionId
    ): array;
}
