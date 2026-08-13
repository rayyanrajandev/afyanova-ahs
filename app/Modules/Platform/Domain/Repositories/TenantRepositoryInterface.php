<?php

namespace App\Modules\Platform\Domain\Repositories;

interface TenantRepositoryInterface
{
    public function findByCode(string $code): ?array;

    public function findById(string $id): ?array;

    public function create(array $attributes): array;

    public function updateById(string $id, array $attributes): ?array;
}
