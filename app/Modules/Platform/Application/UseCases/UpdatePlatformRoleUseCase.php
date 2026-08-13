<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\DuplicatePlatformRoleCodeException;
use App\Modules\Platform\Application\Exceptions\PlatformRoleProtectedException;
use App\Modules\Platform\Domain\Repositories\PlatformRbacRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdatePlatformRoleUseCase
{
    private const RESTRICTED_ROLE_CODE_PATTERNS = [
        'SUPER.ADMIN',
    ];

    private const RESTRICTED_ROLE_CODE_EXACT = [
        'PLATFORM.SUPER.ADMIN',
        'SYSTEM.SUPER.ADMIN',
    ];

    public function __construct(
        private readonly PlatformRbacRepositoryInterface $platformRbacRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existingRole = $this->platformRbacRepository->findRoleById($id);
        if (! $existingRole) {
            return null;
        }

        if (($existingRole['is_system'] ?? false) === true && array_key_exists('code', $payload)) {
            throw new PlatformRoleProtectedException('System role code cannot be changed.');
        }

        $updatePayload = [];

        if (array_key_exists('code', $payload)) {
            $normalizedCode = $this->normalizeCode((string) $payload['code']);

            $this->assertNotRestrictedCode($normalizedCode);

            if ($this->platformRbacRepository->existsRoleCodeInScope(
                code: $normalizedCode,
                tenantId: $existingRole['tenant_id'] ?? null,
                facilityId: $existingRole['facility_id'] ?? null,
                excludeId: $id,
            )) {
                throw new DuplicatePlatformRoleCodeException('Role code already exists for the current scope.');
            }

            $updatePayload['code'] = $normalizedCode;
        }

        if (array_key_exists('name', $payload)) {
            $updatePayload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('status', $payload)) {
            $updatePayload['status'] = trim((string) $payload['status']);
        }

        if (array_key_exists('description', $payload)) {
            $updatePayload['description'] = $this->nullableTrimmedValue($payload['description']);
        }

        $updatedRole = $this->platformRbacRepository->updateRole($id, $updatePayload);
        if (! $updatedRole) {
            return null;
        }

        $changes = $this->extractChanges($existingRole, $updatedRole);
        if ($changes !== []) {
            $statusTransition = null;
            if (array_key_exists('status', $changes)) {
                $statusTransition = [
                    'from' => $existingRole['status'] ?? null,
                    'to' => $updatedRole['status'] ?? null,
                ];
            }

            $metadata = [];
            if ($statusTransition !== null) {
                $metadata['transition'] = $statusTransition;
            }

            $this->platformRbacRepository->writeAuditLog(
                tenantId: $updatedRole['tenant_id'] ?? null,
                facilityId: $updatedRole['facility_id'] ?? null,
                actorId: $actorId,
                action: 'platform-rbac.role.updated',
                targetType: 'role',
                targetId: $id,
                changes: $changes,
                metadata: $metadata,
            );
        }

        return $updatedRole;
    }

    private function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
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
        $trackedFields = ['code', 'name', 'status', 'description'];

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

    private function assertNotRestrictedCode(string $code): void
    {
        if (in_array($code, self::RESTRICTED_ROLE_CODE_EXACT, true)) {
            throw new PlatformRoleProtectedException(
                'This role code is restricted and cannot be used through the admin interface.',
            );
        }

        foreach (self::RESTRICTED_ROLE_CODE_PATTERNS as $pattern) {
            if (str_contains($code, $pattern)) {
                throw new PlatformRoleProtectedException(
                    'Role codes containing "'.$pattern.'" are restricted and cannot be used through the admin interface.',
                );
            }
        }
    }
}
