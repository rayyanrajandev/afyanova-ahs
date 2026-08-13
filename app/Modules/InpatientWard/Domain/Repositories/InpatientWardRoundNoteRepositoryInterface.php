<?php

namespace App\Modules\InpatientWard\Domain\Repositories;

interface InpatientWardRoundNoteRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function acknowledge(string $id, array $attributes): ?array;

    public function search(
        ?string $query,
        ?string $admissionId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;
}
