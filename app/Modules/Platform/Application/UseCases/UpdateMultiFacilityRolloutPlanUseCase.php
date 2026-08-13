<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutPlanStatus;
use DomainException;

class UpdateMultiFacilityRolloutPlanUseCase
{
    public function __construct(
        private readonly MultiFacilityRolloutRepositoryInterface $rolloutRepository,
        private readonly MultiFacilityRolloutAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->rolloutRepository->findPlanById($id);
        if ($existing === null) {
            return null;
        }

        $updatePayload = [];

        if (array_key_exists('rollout_code', $payload)) {
            $rolloutCode = strtoupper(trim((string) $payload['rollout_code']));
            if ($rolloutCode === '') {
                throw new DomainException('Rollout code cannot be blank.');
            }

            $tenantId = (string) ($existing['tenant_id'] ?? '');
            if ($tenantId === '') {
                throw new DomainException('Rollout plan is missing tenant scope.');
            }

            if ($this->rolloutRepository->findPlanByCodeInTenant($tenantId, $rolloutCode, $id) !== null) {
                throw new DomainException('Rollout code already exists in the current tenant scope.');
            }

            $updatePayload['rollout_code'] = $rolloutCode;
        }

        if (array_key_exists('status', $payload)) {
            $status = strtolower(trim((string) $payload['status']));
            if (! in_array($status, MultiFacilityRolloutPlanStatus::values(), true)) {
                throw new DomainException('Invalid rollout status.');
            }

            $updatePayload['status'] = $status;
        }

        if (array_key_exists('target_go_live_at', $payload)) {
            $updatePayload['target_go_live_at'] = $this->nullableTrimmedValue($payload['target_go_live_at']);
        }

        if (array_key_exists('actual_go_live_at', $payload)) {
            $updatePayload['actual_go_live_at'] = $this->nullableTrimmedValue($payload['actual_go_live_at']);
        }

        if (array_key_exists('owner_user_id', $payload)) {
            $updatePayload['owner_user_id'] = $payload['owner_user_id'] === null
                ? null
                : (int) $payload['owner_user_id'];
        }

        if (array_key_exists('metadata', $payload)) {
            $updatePayload['metadata'] = is_array($payload['metadata']) ? $payload['metadata'] : [];
        }

        $nextStatus = (string) ($updatePayload['status'] ?? ($existing['status'] ?? 'draft'));
        $nextTargetGoLiveAt = $updatePayload['target_go_live_at'] ?? ($existing['target_go_live_at'] ?? null);
        $nextOwnerUserId = array_key_exists('owner_user_id', $updatePayload)
            ? $updatePayload['owner_user_id']
            : ($existing['owner_user_id'] ?? null);

        if (in_array($nextStatus, [MultiFacilityRolloutPlanStatus::READY->value, MultiFacilityRolloutPlanStatus::ACTIVE->value], true)
            && $nextTargetGoLiveAt === null) {
            throw new DomainException('targetGoLiveAt is required when status is ready or active.');
        }

        if (in_array($nextStatus, [MultiFacilityRolloutPlanStatus::READY->value, MultiFacilityRolloutPlanStatus::ACTIVE->value], true)
            && $nextOwnerUserId === null) {
            throw new DomainException('ownerUserId is required when status is ready or active.');
        }

        $updated = $this->rolloutRepository->updatePlan($id, $updatePayload);
        if ($updated === null) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $metadata = [];
            if (array_key_exists('status', $changes)) {
                $metadata['transition'] = [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ];
            }

            if (array_key_exists('status', $updatePayload) || array_key_exists('target_go_live_at', $updatePayload)) {
                $metadata['target_go_live_required'] = in_array(
                    $nextStatus,
                    [MultiFacilityRolloutPlanStatus::READY->value, MultiFacilityRolloutPlanStatus::ACTIVE->value],
                    true
                );
                $metadata['target_go_live_provided'] = $nextTargetGoLiveAt !== null;
                $metadata['owner_required'] = in_array(
                    $nextStatus,
                    [MultiFacilityRolloutPlanStatus::READY->value, MultiFacilityRolloutPlanStatus::ACTIVE->value],
                    true
                );
                $metadata['owner_provided'] = $nextOwnerUserId !== null;
            }

            $this->auditLogRepository->write(
                rolloutPlanId: $id,
                action: 'platform.multi-facility-rollout.plan.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: $metadata,
            );
        }

        return $this->rolloutRepository->findPlanById($id) ?? $updated;
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
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'rollout_code',
            'status',
            'target_go_live_at',
            'actual_go_live_at',
            'owner_user_id',
            'rollback_required',
            'rollback_reason',
            'metadata',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }
}
