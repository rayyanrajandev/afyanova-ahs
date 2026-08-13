<?php

namespace App\Modules\Billing\Domain\Repositories;

interface PatientInsuranceRepositoryInterface
{
    /**
     * Find active insurance for a patient
     */
    public function findActiveInsurance(string $patientId, string $tenantId): ?array;

    /**
     * Find insurance record by id
     */
    public function findById(string $id): ?array;

    /**
     * Create patient insurance record
     */
    public function create(array $data): array;

    /**
     * Update patient insurance record
     */
    public function update(string $id, array $data): array;

    /**
     * Delete patient insurance record
     */
    public function delete(string $id): bool;

    /**
     * Find all insurance records for a patient
     */
    public function findByPatientId(string $patientId): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActiveByMemberId(
        string $memberId,
        ?string $tenantId = null,
        ?string $excludeRecordId = null
    ): array;
}
