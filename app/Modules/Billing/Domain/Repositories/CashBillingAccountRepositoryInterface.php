<?php

namespace App\Modules\Billing\Domain\Repositories;

interface CashBillingAccountRepositoryInterface
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paginateForFacility(
        string $tenantId,
        string $facilityId,
        array $filters = [],
        int $page = 1,
        int $perPage = 20,
    ): array;

    /**
     * Find a cash billing account by ID
     *
     * @param string $id
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array;

    /**
     * Find a cash billing account by patient ID
     *
     * @param string $patientId
     * @param string $tenantId
     * @param string $facilityId
     *
     * @return array<string, mixed>|null
     */
    public function findByPatientId(string $patientId, string $tenantId, string $facilityId): ?array;

    /**
     * Create a new cash billing account
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /**
     * Update a cash billing account
     *
     * @param string $id
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array;
}
