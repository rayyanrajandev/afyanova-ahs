<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserStatus;

class ListPlatformUsersUseCase
{
    public function __construct(private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, PlatformUserStatus::values(), true)) {
            $status = null;
        }

        $verification = isset($filters['verification']) ? trim((string) $filters['verification']) : null;
        if (! in_array($verification, ['verified', 'unverified'], true)) {
            $verification = null;
        }

        $roleId = isset($filters['roleId']) ? trim((string) $filters['roleId']) : null;
        $roleId = $roleId === '' ? null : $roleId;

        $facilityId = isset($filters['facilityId']) ? trim((string) $filters['facilityId']) : null;
        $facilityId = $facilityId === '' ? null : $facilityId;

        $sortMap = [
            'name' => 'name',
            'email' => 'email',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'name'] ?? 'name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        return $this->platformUserAdminRepository->searchUsers(
            query: $query,
            status: $status,
            verification: $verification,
            roleId: $roleId,
            facilityId: $facilityId,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
