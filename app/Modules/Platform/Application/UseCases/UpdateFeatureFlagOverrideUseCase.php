<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideRepositoryInterface;

class UpdateFeatureFlagOverrideUseCase
{
    public function __construct(
        private readonly FeatureFlagOverrideRepositoryInterface $overrideRepository,
        private readonly FeatureFlagOverrideAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function execute(string $id, array $payload, ?int $actorId): ?array
    {
        $existing = $this->overrideRepository->findById($id);
        if ($existing === null) {
            return null;
        }

        $updatePayload = [];
        if (array_key_exists('enabled', $payload)) {
            $updatePayload['enabled'] = (bool) $payload['enabled'];
        }
        if (array_key_exists('reason', $payload)) {
            $updatePayload['reason'] = $payload['reason'];
        }
        if (array_key_exists('metadata', $payload)) {
            $updatePayload['metadata'] = $payload['metadata'];
        }

        if ($updatePayload === []) {
            return $existing;
        }

        $updated = $this->overrideRepository->updateById($id, $updatePayload);
        if ($updated === null) {
            return null;
        }

        $enabledTransition = null;
        if (array_key_exists('enabled', $updatePayload)) {
            $enabledTransition = [
                'from' => (bool) ($existing['enabled'] ?? false),
                'to' => (bool) ($updated['enabled'] ?? false),
            ];
        }

        $reasonProvided = false;
        if (array_key_exists('reason', $updatePayload)) {
            $reason = $updatePayload['reason'];
            $reasonProvided = is_string($reason) && trim($reason) !== '';
        }

        $metadata = [];
        if ($enabledTransition !== null) {
            $metadata['transition'] = $enabledTransition;
            $metadata['reason_provided'] = $reasonProvided;
        }

        $this->auditLogRepository->write(
            featureFlagOverrideId: $id,
            action: 'updated',
            actorId: $actorId,
            changes: [
                'before' => $this->auditSnapshot($existing),
                'after' => $this->auditSnapshot($updated),
            ],
            metadata: $metadata,
        );

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function auditSnapshot(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'flag_name' => $row['flag_name'] ?? null,
            'scope_type' => $row['scope_type'] ?? null,
            'scope_key' => $row['scope_key'] ?? null,
            'enabled' => (bool) ($row['enabled'] ?? false),
            'reason' => $row['reason'] ?? null,
            'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : null,
        ];
    }
}
