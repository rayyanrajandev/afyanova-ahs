<?php

namespace App\Modules\ServiceRequest\Domain\Repositories;

interface ServiceRequestRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByRequestNumber(string $requestNumber): bool;

    public function findActiveForPatientAndServiceType(string $patientId, string $serviceType): ?array;

    public function search(
        ?string $patientId,
        ?string $serviceType,
        ?string $status,
        ?string $priority,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        string $sortDirection,
        ?string $departmentId = null,
    ): array;

    public function statusCounts(
        ?string $serviceType,
        ?string $priority,
        ?string $fromDateTime,
        ?string $toDateTime,
        ?string $departmentId = null,
    ): array;

    /**
     * Active walk-ins (pending / in_progress), grouped by patient id.
     * Each value is a list of model rows (newest-first within group).
     *
     * @param  array<int, string>  $patientIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function findActiveByPatientIds(array $patientIds): array;
}
