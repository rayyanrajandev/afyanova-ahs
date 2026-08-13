<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Support\PrivilegedPlatformUserChangePolicy;
use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserStatus;

class UpdatePlatformUserStatusUseCase
{
    public function __construct(
        private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository,
        private readonly PrivilegedPlatformUserChangePolicy $privilegedPlatformUserChangePolicy,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        int $id,
        string $status,
        ?string $reason = null,
        ?string $approvalCaseReference = null,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->platformUserAdminRepository->findUserById($id);
        if (! $existing) {
            return null;
        }

        $normalizedApprovalCaseReference = $this->privilegedPlatformUserChangePolicy->normalizeApprovalCaseReference(
            $approvalCaseReference,
        );
        $privilegedContext = $this->platformUserAdminRepository->findPrivilegedUserContextInScope($id);
        $this->privilegedPlatformUserChangePolicy->assertApprovalCaseReferenceForTarget(
            privilegedContext: $privilegedContext,
            approvalCaseReference: $normalizedApprovalCaseReference,
        );

        $normalizedStatus = in_array($status, PlatformUserStatus::values(), true)
            ? $status
            : PlatformUserStatus::ACTIVE->value;

        $normalizedReason = $this->nullableTrimmedValue($reason);
        $updated = $this->platformUserAdminRepository->updateUser($id, [
            'status' => $normalizedStatus,
            'status_reason' => $normalizedStatus === PlatformUserStatus::INACTIVE->value
                ? $normalizedReason
                : null,
            'deactivated_at' => $normalizedStatus === PlatformUserStatus::INACTIVE->value
                ? now()
                : null,
        ]);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $reasonRequired = $normalizedStatus === PlatformUserStatus::INACTIVE->value;
            $metadata = array_merge(
                $this->privilegedPlatformUserChangePolicy->buildAuditMetadata(
                    privilegedContext: $privilegedContext,
                    approvalCaseReference: $normalizedApprovalCaseReference,
                ),
                [
                    'transition' => [
                        'from' => $existing['status'] ?? null,
                        'to' => $updated['status'] ?? null,
                    ],
                    'reason_required' => $reasonRequired,
                    'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                ],
            );

            $this->platformUserAdminRepository->writeAuditLog(
                tenantId: $this->platformScopeContext->tenantId(),
                facilityId: $this->platformScopeContext->facilityId(),
                actorId: $actorId,
                targetUserId: $id,
                action: 'platform-user.status.updated',
                changes: $changes,
                metadata: $metadata,
            );
        }

        return $updated;
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
        $trackedFields = ['status', 'status_reason', 'deactivated_at'];

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
