<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformUserApprovalCaseRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserApprovalCaseActionType;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserApprovalCaseStatus;
use DomainException;

class CreatePlatformUserApprovalCaseUseCase
{
    public function __construct(
        private readonly PlatformUserApprovalCaseRepositoryInterface $platformUserApprovalCaseRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $facilityId = $this->nullableTrimmedValue($payload['facility_id'] ?? null);
        $targetUserId = $this->nullablePositiveInt($payload['target_user_id'] ?? null);
        $requesterUserId = $this->nullablePositiveInt($payload['requester_user_id'] ?? null);
        $reviewerUserId = $this->nullablePositiveInt($payload['reviewer_user_id'] ?? null);

        if ($targetUserId === null) {
            throw new DomainException('Target user is required.');
        }

        $targetUser = $this->platformUserApprovalCaseRepository->resolveUserInScope($targetUserId);
        if ($targetUser === null) {
            throw new DomainException('Target user was not found in current scope.');
        }

        $requesterUser = null;
        if ($requesterUserId !== null) {
            $requesterUser = $this->platformUserApprovalCaseRepository->resolveUserInScope($requesterUserId);
            if ($requesterUser === null) {
                throw new DomainException('Requester user was not found in current scope.');
            }
        }

        $reviewerUser = null;
        if ($reviewerUserId !== null) {
            $reviewerUser = $this->platformUserApprovalCaseRepository->resolveUserInScope($reviewerUserId);
            if ($reviewerUser === null) {
                throw new DomainException('Reviewer user was not found in current scope.');
            }
        }

        $facility = null;
        if ($facilityId !== null) {
            $facility = $this->platformUserApprovalCaseRepository->resolveFacilityInScope($facilityId);
            if ($facility === null) {
                throw new DomainException('Facility was not found in current scope.');
            }
        }

        $tenantId = $this->resolveTenantId($facility, $targetUser, $requesterUser, $reviewerUser);
        if ($tenantId === null) {
            throw new DomainException('Tenant scope could not be resolved for approval case.');
        }

        $this->assertSameTenantIfPresent($targetUser, $tenantId, 'Target user tenant does not match scope.');
        $this->assertSameTenantIfPresent($requesterUser, $tenantId, 'Requester user tenant does not match scope.');
        $this->assertSameTenantIfPresent($reviewerUser, $tenantId, 'Reviewer user tenant does not match scope.');

        $actionType = strtolower(trim((string) ($payload['action_type'] ?? '')));
        if (! in_array($actionType, PlatformUserApprovalCaseActionType::values(), true)) {
            throw new DomainException('Invalid action type.');
        }

        $caseReference = trim((string) ($payload['case_reference'] ?? ''));
        if ($caseReference === '') {
            throw new DomainException('Case reference is required.');
        }

        if ($this->platformUserApprovalCaseRepository->findCaseByReferenceInTenant($tenantId, $caseReference) !== null) {
            throw new DomainException('Case reference already exists in the current tenant scope.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? PlatformUserApprovalCaseStatus::DRAFT->value)));
        if (! in_array($status, [PlatformUserApprovalCaseStatus::DRAFT->value, PlatformUserApprovalCaseStatus::SUBMITTED->value], true)) {
            throw new DomainException('Invalid initial approval case status.');
        }

        $actionPayload = $payload['action_payload'] ?? [];
        if (! is_array($actionPayload)) {
            $actionPayload = [];
        }

        $created = $this->platformUserApprovalCaseRepository->createCase([
            'tenant_id' => $tenantId,
            'facility_id' => $facility['id'] ?? $facilityId,
            'target_user_id' => $targetUserId,
            'requester_user_id' => $requesterUserId,
            'reviewer_user_id' => $reviewerUserId,
            'case_reference' => $caseReference,
            'action_type' => $actionType,
            'action_payload' => $actionPayload,
            'status' => $status,
            'decision_reason' => null,
            'submitted_at' => $status === PlatformUserApprovalCaseStatus::SUBMITTED->value ? now() : null,
            'decided_at' => null,
        ]);

        $approvalCaseId = (string) ($created['id'] ?? '');
        $this->platformUserApprovalCaseRepository->writeAuditLog(
            approvalCaseId: $approvalCaseId,
            action: 'platform.user-approval-case.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $this->platformUserApprovalCaseRepository->findCaseById($approvalCaseId) ?? $created;
    }

    /**
     * @param  array<string, mixed>|null  $facility
     * @param  array<string, mixed>|null  $targetUser
     * @param  array<string, mixed>|null  $requesterUser
     * @param  array<string, mixed>|null  $reviewerUser
     */
    private function resolveTenantId(?array $facility, ?array $targetUser, ?array $requesterUser, ?array $reviewerUser): ?string
    {
        $fromFacility = isset($facility['tenant_id']) ? trim((string) $facility['tenant_id']) : '';
        if ($fromFacility !== '') {
            return $fromFacility;
        }

        foreach ([$targetUser, $requesterUser, $reviewerUser] as $user) {
            if (! is_array($user)) {
                continue;
            }

            $tenantId = trim((string) ($user['tenant_id'] ?? ''));
            if ($tenantId !== '') {
                return $tenantId;
            }
        }

        $scopeTenantId = $this->platformScopeContext->tenantId();

        return $scopeTenantId !== null && trim($scopeTenantId) !== ''
            ? trim($scopeTenantId)
            : null;
    }

    /**
     * @param  array<string, mixed>|null  $user
     */
    private function assertSameTenantIfPresent(?array $user, string $tenantId, string $message): void
    {
        if (! is_array($user)) {
            return;
        }

        $userTenantId = trim((string) ($user['tenant_id'] ?? ''));
        if ($userTenantId === '' || $userTenantId === $tenantId) {
            return;
        }

        throw new DomainException($message);
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        $resolved = (int) $normalized;

        return $resolved > 0 ? $resolved : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $approvalCase): array
    {
        $tracked = [
            'tenant_id',
            'facility_id',
            'target_user_id',
            'requester_user_id',
            'reviewer_user_id',
            'case_reference',
            'action_type',
            'action_payload',
            'status',
            'decision_reason',
            'submitted_at',
            'decided_at',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $approvalCase[$field] ?? null;
        }

        return $result;
    }
}

