<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagRepositoryInterface;
use DomainException;

class CreateFeatureFlagOverrideUseCase
{
    public function __construct(
        private readonly FeatureFlagOverrideRepositoryInterface $overrideRepository,
        private readonly FeatureFlagOverrideAuditLogRepositoryInterface $auditLogRepository,
        private readonly FeatureFlagRepositoryInterface $featureFlagRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, ?int $actorId): array
    {
        $normalized = $this->normalizePayload($payload);

        $flags = $this->featureFlagRepository->all();
        if (! array_key_exists($normalized['flag_name'], $flags)) {
            throw new DomainException('Feature flag does not exist.');
        }

        $existing = $this->overrideRepository->findByIdentity(
            flagName: $normalized['flag_name'],
            scopeType: $normalized['scope_type'],
            scopeKey: $normalized['scope_key'],
        );

        if ($existing !== null) {
            throw new DomainException('Feature flag override already exists for this scope.');
        }

        $created = $this->overrideRepository->create($normalized);

        $this->auditLogRepository->write(
            featureFlagOverrideId: (string) $created['id'],
            action: 'created',
            actorId: $actorId,
            changes: [
                'before' => null,
                'after' => $this->auditSnapshot($created),
            ],
        );

        return $created;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $scopeType = strtolower(trim((string) ($payload['scope_type'] ?? '')));
        $scopeKey = trim((string) ($payload['scope_key'] ?? ''));
        if ($scopeType === 'country') {
            $scopeKey = strtoupper($scopeKey);
        }

        return [
            'flag_name' => trim((string) ($payload['flag_name'] ?? '')),
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'reason' => $payload['reason'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ];
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
