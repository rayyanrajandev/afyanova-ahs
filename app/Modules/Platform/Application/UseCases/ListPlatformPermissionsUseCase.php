<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformRbacRepositoryInterface;

class ListPlatformPermissionsUseCase
{
    public function __construct(private readonly PlatformRbacRepositoryInterface $platformRbacRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 50), 1), 200);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->platformRbacRepository->searchPermissions(
            query: $query,
            page: $page,
            perPage: $perPage,
        );
    }
}

