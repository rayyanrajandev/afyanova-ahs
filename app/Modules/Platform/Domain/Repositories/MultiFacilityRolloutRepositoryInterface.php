<?php

namespace App\Modules\Platform\Domain\Repositories;

interface MultiFacilityRolloutRepositoryInterface
{
    public function searchPlans(
        ?string $query,
        ?string $status,
        ?string $facilityId,
        ?string $goLiveFrom,
        ?string $goLiveTo,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function findPlanById(string $id): ?array;

    public function findPlanByCodeInTenant(string $tenantId, string $rolloutCode, ?string $excludePlanId = null): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function resolveFacilityInScope(string $facilityId): ?array;

    public function createPlan(array $attributes): array;

    public function updatePlan(string $id, array $attributes): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCheckpoints(string $rolloutPlanId): array;

    public function upsertCheckpoint(string $rolloutPlanId, string $checkpointCode, array $attributes): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listIncidents(string $rolloutPlanId): array;

    public function findIncidentById(string $rolloutPlanId, string $incidentId): ?array;

    public function findIncidentByCode(string $rolloutPlanId, string $incidentCode): ?array;

    public function createIncident(string $rolloutPlanId, array $attributes): array;

    public function updateIncident(string $rolloutPlanId, string $incidentId, array $attributes): ?array;

    public function upsertAcceptance(string $rolloutPlanId, array $attributes): array;

    public function getAcceptance(string $rolloutPlanId): ?array;
}
