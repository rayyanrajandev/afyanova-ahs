<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideRepositoryInterface;

class DeleteFeatureFlagOverrideUseCase
{
    public function __construct(
        private readonly FeatureFlagOverrideRepositoryInterface $overrideRepository,
        private readonly FeatureFlagOverrideAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    public function execute(string $id, ?int $actorId): bool
    {
        $existing = $this->overrideRepository->findById($id);
        if ($existing === null) {
            return false;
        }

        $deleted = $this->overrideRepository->deleteById($id);
        if (! $deleted) {
            return false;
        }

        $this->auditLogRepository->write(
            featureFlagOverrideId: $id,
            action: 'deleted',
            actorId: $actorId,
            changes: [
                'before' => [
                    'id' => $existing['id'] ?? null,
                    'flag_name' => $existing['flag_name'] ?? null,
                    'scope_type' => $existing['scope_type'] ?? null,
                    'scope_key' => $existing['scope_key'] ?? null,
                    'enabled' => (bool) ($existing['enabled'] ?? false),
                    'reason' => $existing['reason'] ?? null,
                    'metadata' => is_array($existing['metadata'] ?? null) ? $existing['metadata'] : null,
                ],
                'after' => null,
            ],
        );

        return true;
    }
}
