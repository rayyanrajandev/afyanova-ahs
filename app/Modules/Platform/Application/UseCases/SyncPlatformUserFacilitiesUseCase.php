<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\InvalidPlatformUserFacilityAssignmentsException;
use App\Modules\Platform\Application\Exceptions\UnknownPlatformUserFacilityException;
use App\Modules\Platform\Application\Support\PrivilegedPlatformUserChangePolicy;
use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class SyncPlatformUserFacilitiesUseCase
{
    public function __construct(
        private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository,
        private readonly PrivilegedPlatformUserChangePolicy $privilegedPlatformUserChangePolicy,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        int $userId,
        array $facilityAssignments,
        ?string $approvalCaseReference = null,
        ?int $actorId = null
    ): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $normalizedApprovalCaseReference = $this->privilegedPlatformUserChangePolicy->normalizeApprovalCaseReference(
            $approvalCaseReference,
        );
        $privilegedContext = $this->platformUserAdminRepository->findPrivilegedUserContextInScope($userId);
        $this->privilegedPlatformUserChangePolicy->assertApprovalCaseReferenceForTarget(
            privilegedContext: $privilegedContext,
            approvalCaseReference: $normalizedApprovalCaseReference,
        );

        $normalizedAssignments = $this->normalizeAssignments($facilityAssignments);
        $facilityIds = array_values(array_map(
            static fn (array $assignment): string => (string) ($assignment['facility_id'] ?? ''),
            $normalizedAssignments,
        ));

        $resolvedFacilityIds = $this->platformUserAdminRepository->resolveExistingFacilityIdsInScope($facilityIds);
        if (count($resolvedFacilityIds) !== count($facilityIds)) {
            throw new UnknownPlatformUserFacilityException(
                'One or more facilities are invalid or outside the current scope.',
            );
        }

        $activePrimaryCount = count(array_filter(
            $normalizedAssignments,
            static fn (array $assignment): bool => (bool) $assignment['is_active'] && (bool) $assignment['is_primary'],
        ));
        if ($activePrimaryCount > 1) {
            throw new InvalidPlatformUserFacilityAssignmentsException(
                'Only one active primary facility assignment is allowed.',
            );
        }

        $beforeAssignments = $this->platformUserAdminRepository->listUserFacilityAssignmentsInScope($userId);
        $updatedUser = $this->platformUserAdminRepository->syncUserFacilitiesInScope($userId, $normalizedAssignments);
        if (! $updatedUser) {
            return null;
        }

        $afterAssignments = $this->platformUserAdminRepository->listUserFacilityAssignmentsInScope($userId);
        $this->platformUserAdminRepository->writeAuditLog(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            actorId: $actorId,
            targetUserId: $userId,
            action: 'platform-user.facilities.synced',
            changes: [
                'facility_assignments' => [
                    'before' => $beforeAssignments,
                    'after' => $afterAssignments,
                ],
            ],
            metadata: $this->privilegedPlatformUserChangePolicy->buildAuditMetadata(
                privilegedContext: $privilegedContext,
                approvalCaseReference: $normalizedApprovalCaseReference,
            ),
        );

        return $updatedUser;
    }

    /**
     * @param  array<int, array<string, mixed>>  $facilityAssignments
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssignments(array $facilityAssignments): array
    {
        $byFacilityId = [];

        foreach ($facilityAssignments as $assignment) {
            $facilityId = trim((string) ($assignment['facility_id'] ?? ''));
            if ($facilityId === '') {
                continue;
            }

            $role = isset($assignment['role']) ? trim((string) $assignment['role']) : null;
            $byFacilityId[$facilityId] = [
                'facility_id' => $facilityId,
                'role' => $role === '' ? null : $role,
                'is_primary' => (bool) ($assignment['is_primary'] ?? false),
                'is_active' => array_key_exists('is_active', $assignment)
                    ? (bool) $assignment['is_active']
                    : true,
            ];
        }

        return array_values($byFacilityId);
    }
}
