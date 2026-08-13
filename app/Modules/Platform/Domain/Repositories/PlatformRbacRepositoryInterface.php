<?php

namespace App\Modules\Platform\Domain\Repositories;

interface PlatformRbacRepositoryInterface
{
    public function searchPermissions(
        ?string $query,
        int $page,
        int $perPage
    ): array;

    public function searchRoles(
        ?string $query,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        ?string $facilityId = null,
        ?bool $assignableOnly = false,
    ): array;

    public function findRoleById(string $id): ?array;

    public function createRole(array $attributes): array;

    public function updateRole(string $id, array $attributes): ?array;

    public function deleteRole(string $id): bool;

    public function existsRoleCodeInScope(
        string $code,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool;

    /**
     * @param  array<int, string>  $permissionNames
     */
    public function syncRolePermissions(string $roleId, array $permissionNames): ?array;

    /**
     * @return array<int, string>
     */
    public function resolveExistingPermissionNames(array $permissionNames): array;

    /**
     * @param  array<int, string>  $roleIds
     * @return array<int, string>
     */
    public function resolveExistingRoleIdsInScope(array $roleIds): array;

    /**
     * @param  array<int, string>  $roleIds
     * @return array<string, mixed>|null
     */
    public function syncUserRoles(int $userId, array $roleIds): ?array;

    public function writeAuditLog(
        ?string $tenantId,
        ?string $facilityId,
        ?int $actorId,
        string $action,
        ?string $targetType,
        ?string $targetId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listAuditLogs(
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $targetType,
        ?string $targetId,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}

