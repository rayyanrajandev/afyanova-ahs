<?php

namespace App\Modules\Platform\Domain\Repositories;

interface PlatformUserApprovalCaseRepositoryInterface
{
    public function searchCases(
        ?string $query,
        ?string $status,
        ?string $actionType,
        ?int $targetUserId,
        ?int $requesterUserId,
        ?int $reviewerUserId,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function findCaseById(string $id): ?array;

    public function findCaseByReferenceInTenant(string $tenantId, string $caseReference, ?string $excludeCaseId = null): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function resolveFacilityInScope(string $facilityId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function resolveUserInScope(int $userId): ?array;

    public function createCase(array $attributes): array;

    public function updateCase(string $id, array $attributes): ?array;

    public function createComment(string $approvalCaseId, array $attributes): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listComments(string $approvalCaseId): array;

    public function writeAuditLog(
        string $approvalCaseId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listAuditLogs(
        string $approvalCaseId,
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

