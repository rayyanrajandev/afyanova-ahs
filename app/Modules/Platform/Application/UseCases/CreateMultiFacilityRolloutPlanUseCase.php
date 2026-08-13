<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutCheckpointStatus;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutPlanStatus;
use DomainException;

class CreateMultiFacilityRolloutPlanUseCase
{
    /**
     * @var array<int, array{code: string, name: string}>
     */
    private const DEFAULT_CHECKPOINTS = [
        ['code' => 'FACILITY_PROFILE_CONFIRMED', 'name' => 'Facility profile confirmed'],
        ['code' => 'SUBSCRIPTION_ACCESS_CONFIRMED', 'name' => 'Subscription access confirmed'],
        ['code' => 'ADMIN_OWNER_ASSIGNED', 'name' => 'Facility admin owner assigned'],
        ['code' => 'USER_ACCESS_REVIEWED', 'name' => 'Users and permissions reviewed'],
        ['code' => 'PATIENT_REGISTRATION_READY', 'name' => 'Patient registration workflow ready'],
        ['code' => 'TRAINING_COMPLETED', 'name' => 'Operational training completed'],
        ['code' => 'GO_LIVE_SUPPORT_READY', 'name' => 'Go-live support coverage ready'],
        ['code' => 'ACCEPTANCE_EVIDENCE_READY', 'name' => 'Acceptance evidence ready'],
    ];

    public function __construct(
        private readonly MultiFacilityRolloutRepositoryInterface $rolloutRepository,
        private readonly MultiFacilityRolloutAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $facilityId = trim((string) ($payload['facility_id'] ?? ''));
        if ($facilityId === '') {
            throw new DomainException('Facility is required.');
        }

        $facility = $this->rolloutRepository->resolveFacilityInScope($facilityId);
        if ($facility === null) {
            throw new DomainException('Facility not found in current scope.');
        }

        $rolloutCode = strtoupper(trim((string) ($payload['rollout_code'] ?? '')));
        if ($rolloutCode === '') {
            throw new DomainException('Rollout code is required.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? MultiFacilityRolloutPlanStatus::DRAFT->value)));
        if (! in_array($status, MultiFacilityRolloutPlanStatus::values(), true)) {
            throw new DomainException('Invalid rollout status.');
        }

        $targetGoLiveAt = isset($payload['target_go_live_at'])
            ? trim((string) $payload['target_go_live_at'])
            : null;
        $targetGoLiveAt = $targetGoLiveAt === '' ? null : $targetGoLiveAt;

        if (in_array($status, [MultiFacilityRolloutPlanStatus::READY->value, MultiFacilityRolloutPlanStatus::ACTIVE->value], true)
            && $targetGoLiveAt === null) {
            throw new DomainException('targetGoLiveAt is required when status is ready or active.');
        }

        $ownerUserId = isset($payload['owner_user_id']) ? (int) $payload['owner_user_id'] : null;
        if (in_array($status, [MultiFacilityRolloutPlanStatus::READY->value, MultiFacilityRolloutPlanStatus::ACTIVE->value], true)
            && $ownerUserId === null) {
            throw new DomainException('ownerUserId is required when status is ready or active.');
        }

        $tenantId = (string) ($facility['tenant_id'] ?? '');
        if ($tenantId === '') {
            throw new DomainException('Resolved facility does not include tenant scope.');
        }

        if ($this->rolloutRepository->findPlanByCodeInTenant($tenantId, $rolloutCode) !== null) {
            throw new DomainException('Rollout code already exists in the current tenant scope.');
        }

        $created = $this->rolloutRepository->createPlan([
            'tenant_id' => $tenantId,
            'facility_id' => (string) $facility['id'],
            'rollout_code' => $rolloutCode,
            'status' => $status,
            'target_go_live_at' => $targetGoLiveAt,
            'actual_go_live_at' => $this->nullableTrimmedValue($payload['actual_go_live_at'] ?? null),
            'owner_user_id' => $ownerUserId,
            'rollback_required' => false,
            'rollback_reason' => null,
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        ]);

        foreach (self::DEFAULT_CHECKPOINTS as $checkpoint) {
            $this->rolloutRepository->upsertCheckpoint((string) $created['id'], $checkpoint['code'], [
                'checkpoint_name' => $checkpoint['name'],
                'status' => MultiFacilityRolloutCheckpointStatus::NOT_STARTED->value,
                'decision_notes' => null,
                'completed_by_user_id' => null,
                'completed_at' => null,
            ]);
        }

        $this->auditLogRepository->write(
            rolloutPlanId: (string) $created['id'],
            action: 'platform.multi-facility-rollout.plan.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
            metadata: [
                'facilityCode' => $facility['code'] ?? null,
                'facilityName' => $facility['name'] ?? null,
            ],
        );

        return $this->rolloutRepository->findPlanById((string) $created['id']) ?? $created;
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $plan): array
    {
        $tracked = [
            'tenant_id',
            'facility_id',
            'rollout_code',
            'status',
            'target_go_live_at',
            'actual_go_live_at',
            'owner_user_id',
            'rollback_required',
            'rollback_reason',
            'metadata',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $plan[$field] ?? null;
        }

        return $result;
    }
}
