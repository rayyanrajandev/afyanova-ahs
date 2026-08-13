<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogHoldRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\TenantRepositoryInterface;
use DomainException;

class CreateCrossTenantAdminAuditLogHoldUseCase
{
    public function __construct(
        private readonly CrossTenantAdminAuditLogHoldRepositoryInterface $holdRepository,
        private readonly CrossTenantAdminAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantRepositoryInterface $tenantRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, ?int $actorId): array
    {
        $holdCode = strtoupper(trim((string) ($payload['hold_code'] ?? '')));
        if ($holdCode === '') {
            throw new DomainException('Hold code is required.');
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException('Reason is required.');
        }

        if ($this->holdRepository->findByHoldCode($holdCode) !== null) {
            throw new DomainException('Hold code already exists.');
        }

        $targetTenantCode = isset($payload['target_tenant_code']) && trim((string) $payload['target_tenant_code']) !== ''
            ? strtoupper(trim((string) $payload['target_tenant_code']))
            : null;

        $targetTenantId = null;
        if ($targetTenantCode !== null) {
            $tenant = $this->tenantRepository->findByCode($targetTenantCode);
            if ($tenant === null) {
                throw new DomainException('Target tenant code is invalid.');
            }

            $targetTenantId = (string) ($tenant['id'] ?? '');
        }

        $action = isset($payload['action']) && trim((string) $payload['action']) !== ''
            ? trim((string) $payload['action'])
            : null;

        $approvalCaseReference = trim((string) ($payload['approval_case_reference'] ?? ''));
        if ($approvalCaseReference === '') {
            throw new DomainException('Approval case reference is required.');
        }

        $approvedByUserId = isset($payload['approved_by_user_id']) ? (int) $payload['approved_by_user_id'] : 0;
        if ($approvedByUserId < 1) {
            throw new DomainException('Approved by user is required.');
        }

        if ((bool) config('platform_audit_retention.cross_tenant_admin_audit_logs.holds.governance.enforce_two_person_control', false)
            && $actorId !== null
            && $approvedByUserId === $actorId) {
            throw new DomainException('Two-person control requires a different approver for hold creation.');
        }

        $reviewDueAt = $payload['review_due_at'] ?? null;
        if ($reviewDueAt === null || trim((string) $reviewDueAt) === '') {
            throw new DomainException('Review due date is required.');
        }

        $startsAt = $payload['starts_at'] ?? null;
        $endsAt = $payload['ends_at'] ?? null;
        if ($startsAt !== null && $endsAt !== null && strtotime((string) $endsAt) < strtotime((string) $startsAt)) {
            throw new DomainException('endsAt must be after or equal to startsAt.');
        }

        $created = $this->holdRepository->create([
            'hold_code' => $holdCode,
            'reason' => $reason,
            'approval_case_reference' => $approvalCaseReference,
            'target_tenant_code' => $targetTenantCode,
            'action' => $action,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => true,
            'created_by_user_id' => $actorId,
            'approved_by_user_id' => $approvedByUserId,
            'review_due_at' => $reviewDueAt,
            'released_at' => null,
            'released_by_user_id' => null,
            'release_reason' => null,
        ]);

        $this->auditLogRepository->write(
            action: 'platform-admin.audit-log-holds.create',
            operationType: 'write',
            actorId: $actorId,
            targetTenantId: $targetTenantId,
            targetTenantCode: $targetTenantCode,
            targetResourceType: 'cross_tenant_audit_log_hold',
            targetResourceId: (string) ($created['id'] ?? null),
            outcome: 'success',
            reason: $reason,
            metadata: [
                'hold' => [
                    'holdCode' => $created['hold_code'] ?? null,
                    'approvalCaseReference' => $created['approval_case_reference'] ?? null,
                    'approvedByUserId' => $created['approved_by_user_id'] ?? null,
                    'reviewDueAt' => $created['review_due_at'] ?? null,
                    'action' => $created['action'] ?? null,
                    'startsAt' => $created['starts_at'] ?? null,
                    'endsAt' => $created['ends_at'] ?? null,
                ],
            ],
        );

        return $created;
    }
}
