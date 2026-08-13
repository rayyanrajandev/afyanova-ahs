<?php

namespace App\Modules\Platform\Domain\Repositories;

interface PlatformUserAdminRepositoryInterface
{
    public function searchUsers(
        ?string $query,
        ?string $status,
        ?string $verification,
        ?string $roleId,
        ?string $facilityId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function findUserById(int $id): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findPrivilegedUserContextInScope(int $userId): ?array;

    public function createUser(array $attributes): array;

    public function updateUser(int $id, array $attributes): ?array;

    public function statusCounts(?string $query, ?string $verification, ?string $roleId, ?string $facilityId): array;

    public function emailExists(string $email, ?int $excludeUserId = null): bool;

    /**
     * @param  array<int, string>  $facilityIds
     * @return array<int, string>
     */
    public function resolveExistingFacilityIdsInScope(array $facilityIds): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUserFacilityAssignmentsInScope(int $userId): array;

    /**
     * @param  array<int, array<string, mixed>>  $facilityAssignments
     */
    public function syncUserFacilitiesInScope(int $userId, array $facilityAssignments): ?array;

    public function writeAuditLog(
        ?string $tenantId,
        ?string $facilityId,
        ?int $actorId,
        ?int $targetUserId,
        string $action,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listAuditLogs(
        int $targetUserId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
