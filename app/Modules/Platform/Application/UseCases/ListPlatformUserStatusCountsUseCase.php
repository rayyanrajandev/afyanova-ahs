<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;

class ListPlatformUserStatusCountsUseCase
{
    public function __construct(private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $verification = isset($filters['verification']) ? trim((string) $filters['verification']) : null;
        if (! in_array($verification, ['verified', 'unverified'], true)) {
            $verification = null;
        }

        $roleId = isset($filters['roleId']) ? trim((string) $filters['roleId']) : null;
        $roleId = $roleId === '' ? null : $roleId;

        $facilityId = isset($filters['facilityId']) ? trim((string) $filters['facilityId']) : null;
        $facilityId = $facilityId === '' ? null : $facilityId;

        return $this->platformUserAdminRepository->statusCounts(
            query: $query,
            verification: $verification,
            roleId: $roleId,
            facilityId: $facilityId,
        );
    }
}
