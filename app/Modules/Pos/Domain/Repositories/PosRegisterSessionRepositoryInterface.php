<?php

namespace App\Modules\Pos\Domain\Repositories;

interface PosRegisterSessionRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id, bool $lockForUpdate = false): ?array;

    public function findOpenByRegisterId(string $registerId, bool $lockForUpdate = false): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsBySessionNumber(string $sessionNumber): bool;

    public function search(
        ?string $query,
        ?string $registerId,
        ?string $status,
        int $page,
        int $perPage
    ): array;
}
