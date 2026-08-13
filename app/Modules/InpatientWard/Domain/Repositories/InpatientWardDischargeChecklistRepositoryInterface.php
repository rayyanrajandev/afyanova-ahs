<?php

namespace App\Modules\InpatientWard\Domain\Repositories;

interface InpatientWardDischargeChecklistRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function findByAdmissionId(string $admissionId): ?array;

    public function search(
        ?string $query,
        ?string $status,
        ?string $admissionId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $admissionId
    ): array;
}

