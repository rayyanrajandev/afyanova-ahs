<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogHoldRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogRepositoryInterface;
use DomainException;

class ReleaseCrossTenantAdminAuditLogHoldUseCase
{
    public function __construct(
        private readonly CrossTenantAdminAuditLogHoldRepositoryInterface $holdRepository,
        private readonly CrossTenantAdminAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(
        string $id,
        string $releaseReason,
        string $releaseCaseReference,
        int $releaseApprovedByUserId,
        ?int $actorId
    ): ?array {
        $existing = $this->holdRepository->findById($id);
        if ($existing === null) {
            return null;
        }

        if (! (bool) ($existing['is_active'] ?? false) || ($existing['released_at'] ?? null) !== null) {
            throw new DomainException('Audit log hold is already released.');
        }

        $normalizedReleaseCaseReference = trim($releaseCaseReference);
        if ($normalizedReleaseCaseReference === '') {
            throw new DomainException('Release case reference is required.');
        }

        if ($releaseApprovedByUserId < 1) {
            throw new DomainException('Release approver is required.');
        }

        $normalizedReleaseReason = trim($releaseReason);
        if ($normalizedReleaseReason === '') {
            throw new DomainException('Release reason is required.');
        }

        if ((bool) config('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', false)
            && $actorId !== null
            && $releaseApprovedByUserId === $actorId) {
            throw new DomainException('Two-person control requires a different approver for hold release.');
        }

        $released = $this->holdRepository->release($id, [
            'is_active' => false,
            'released_at' => now(),
            'released_by_user_id' => $actorId,
            'release_reason' => $normalizedReleaseReason,
            'release_case_reference' => $normalizedReleaseCaseReference,
            'release_approved_by_user_id' => $releaseApprovedByUserId,
        ]);

        if ($released === null) {
            return null;
        }

        $this->auditLogRepository->write(
            action: 'platform-admin.audit-log-holds.release',
            operationType: 'write',
            actorId: $actorId,
            targetTenantId: null,
            targetTenantCode: $released['target_tenant_code'] ?? null,
            targetResourceType: 'cross_tenant_audit_log_hold',
            targetResourceId: (string) ($released['id'] ?? null),
            outcome: 'success',
            reason: $normalizedReleaseReason,
            metadata: [
                'transition' => [
                    'is_active' => [
                        'from' => (bool) ($existing['is_active'] ?? false),
                        'to' => (bool) ($released['is_active'] ?? false),
                    ],
                ],
                'release_reason_required' => true,
                'release_reason_provided' => $normalizedReleaseReason !== '',
                'hold' => [
                    'holdCode' => $released['hold_code'] ?? null,
                    'action' => $released['action'] ?? null,
                    'releasedAt' => $released['released_at'] ?? null,
                    'releaseCaseReference' => $released['release_case_reference'] ?? null,
                    'releaseApprovedByUserId' => $released['release_approved_by_user_id'] ?? null,
                ],
            ],
        );

        return $released;
    }
}
