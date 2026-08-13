<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryMsdOrderRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function search(
        ?string $query,
        ?string $status,
        int $page,
        int $perPage
    ): array;
}
