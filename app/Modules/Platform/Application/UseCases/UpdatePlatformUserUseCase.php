<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\DuplicatePlatformUserEmailException;
use App\Modules\Platform\Application\Support\PrivilegedPlatformUserChangePolicy;
use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdatePlatformUserUseCase
{
    public function __construct(
        private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository,
        private readonly PrivilegedPlatformUserChangePolicy $privilegedPlatformUserChangePolicy,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(int $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->platformUserAdminRepository->findUserById($id);
        if (! $existing) {
            return null;
        }

        $normalizedApprovalCaseReference = $this->privilegedPlatformUserChangePolicy->normalizeApprovalCaseReference(
            isset($payload['approval_case_reference']) ? (string) $payload['approval_case_reference'] : null,
        );
        $privilegedContext = $this->platformUserAdminRepository->findPrivilegedUserContextInScope($id);
        $this->privilegedPlatformUserChangePolicy->assertApprovalCaseReferenceForTarget(
            privilegedContext: $privilegedContext,
            approvalCaseReference: $normalizedApprovalCaseReference,
        );

        $updatePayload = [];

        if (array_key_exists('name', $payload)) {
            $updatePayload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('email', $payload)) {
            $normalizedEmail = strtolower(trim((string) $payload['email']));
            if ($this->platformUserAdminRepository->emailExists($normalizedEmail, $id)) {
                throw new DuplicatePlatformUserEmailException('User email already exists.');
            }

            $updatePayload['email'] = $normalizedEmail;
        }

        $updated = $this->platformUserAdminRepository->updateUser($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->platformUserAdminRepository->writeAuditLog(
                tenantId: $this->platformScopeContext->tenantId(),
                facilityId: $this->platformScopeContext->facilityId(),
                actorId: $actorId,
                targetUserId: $id,
                action: 'platform-user.updated',
                changes: $changes,
                metadata: $this->privilegedPlatformUserChangePolicy->buildAuditMetadata(
                    privilegedContext: $privilegedContext,
                    approvalCaseReference: $normalizedApprovalCaseReference,
                ),
            );
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = ['name', 'email'];

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
