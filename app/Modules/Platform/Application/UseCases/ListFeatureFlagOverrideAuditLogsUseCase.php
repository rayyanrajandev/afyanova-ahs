<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideAuditLogRepositoryInterface;

class ListFeatureFlagOverrideAuditLogsUseCase
{
    public function __construct(private readonly FeatureFlagOverrideAuditLogRepositoryInterface $auditLogRepository) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function execute(string $featureFlagOverrideId, array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        return $this->auditLogRepository->listByFeatureFlagOverrideId(
            featureFlagOverrideId: $featureFlagOverrideId,
            page: $page,
            perPage: $perPage,
        );
    }
}
