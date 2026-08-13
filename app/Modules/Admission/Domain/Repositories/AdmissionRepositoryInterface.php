<?php

namespace App\Modules\Admission\Domain\Repositories;

interface AdmissionRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByAdmissionNumber(string $admissionNumber): bool;

    /**
     * Most recent active (admitted/transferred) admission for a patient, if
     * any — mirrors EmergencyTriageCaseRepositoryInterface::findActiveForPatient().
     */
    public function findActiveForPatient(string $patientId): ?array;

    public function hasActivePlacementConflict(
        string $ward,
        string $bed,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeAdmissionId = null
    ): bool;

    /**
     * Real-FK counterpart to hasActivePlacementConflict() — kept as a
     * separate method rather than overloading that one, since the two
     * conflict checks (string-matched vs resource-id-matched) need to
     * coexist while historical rows only have the string pair populated.
     */
    public function hasActiveBedResourceConflict(
        string $bedResourceId,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeAdmissionId = null
    ): bool;

    /**
     * Bulk occupancy lookup for a page of bed resources — one query instead
     * of N, keyed by bed_resource_id. Only admitted/transferred (active)
     * admissions are considered occupying.
     *
     * @param  array<int, string>  $bedResourceIds
     * @return array<string, array<string, mixed>> bed_resource_id => admission row
     */
    public function activeAdmissionsByBedResourceIds(array $bedResourceIds): array;

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $ward,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?string $ward,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $dischargedFrom = null,
        ?string $dischargedTo = null
    ): array;
}


