<?php

namespace App\Modules\Platform\Domain\Repositories;

interface FeatureFlagOverrideAuditLogRepositoryInterface
{
    public function write(
        string $featureFlagOverrideId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listByFeatureFlagOverrideId(string $featureFlagOverrideId, int $page, int $perPage): array;
}
