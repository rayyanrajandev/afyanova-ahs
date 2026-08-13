<?php

namespace App\Modules\Platform\Domain\Repositories;

interface CrossTenantAdminAuditLogHoldRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByHoldCode(string $holdCode): ?array;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters, int $page, int $perPage): array;

    public function release(string $id, array $attributes): ?array;
}
