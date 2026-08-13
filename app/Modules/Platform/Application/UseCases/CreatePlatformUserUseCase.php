<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\DuplicatePlatformUserEmailException;
use App\Modules\Platform\Application\Exceptions\UnknownPlatformRbacRoleException;
use App\Modules\Platform\Domain\Repositories\PlatformRbacRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\PlatformUserAdminRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserStatus;
use Illuminate\Support\Str;

class CreatePlatformUserUseCase
{
    public function __construct(
        private readonly PlatformUserAdminRepositoryInterface $platformUserAdminRepository,
        private readonly PlatformRbacRepositoryInterface $platformRbacRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $email = strtolower(trim((string) $payload['email']));
        if ($this->platformUserAdminRepository->emailExists($email)) {
            throw new DuplicatePlatformUserEmailException('User email already exists.');
        }

        $created = $this->platformUserAdminRepository->createUser([
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'name' => trim((string) $payload['name']),
            'email' => $email,
            'password' => Str::password(24),
            'status' => PlatformUserStatus::ACTIVE->value,
            'status_reason' => null,
            'deactivated_at' => null,
        ]);

        $targetUserId = isset($created['id']) && is_numeric($created['id'])
            ? (int) $created['id']
            : null;

        $facilityId = $this->platformScopeContext->facilityId();
        if ($targetUserId !== null && $facilityId !== null) {
            $assigned = $this->platformUserAdminRepository->syncUserFacilitiesInScope($targetUserId, [
                [
                    'facility_id' => $facilityId,
                    'role' => 'staff',
                    'is_primary' => true,
                    'is_active' => true,
                ],
            ]);

            if ($assigned !== null) {
                $created = $assigned;
            }
        }

        $roleIds = $payload['role_ids'] ?? [];
        if ($roleIds !== [] && $targetUserId !== null) {
            $resolvedRoleIds = $this->platformRbacRepository->resolveExistingRoleIdsInScope($roleIds);

            if (count($resolvedRoleIds) !== count($roleIds)) {
                throw new UnknownPlatformRbacRoleException('One or more role ids are invalid for the current scope.');
            }

            $this->platformRbacRepository->syncUserRoles($targetUserId, $resolvedRoleIds);
            $created['assigned_role_ids'] = $resolvedRoleIds;
        }

        $this->platformUserAdminRepository->writeAuditLog(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $facilityId,
            actorId: $actorId,
            targetUserId: $targetUserId,
            action: 'platform-user.created',
            changes: [
                'after' => $this->extractTrackedFields($created),
                'assigned_role_ids' => $roleIds,
            ],
        );

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $user): array
    {
        $tracked = [
            'id',
            'tenant_id',
            'name',
            'email',
            'status',
            'status_reason',
            'deactivated_at',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $user[$field] ?? null;
        }

        return $result;
    }
}
