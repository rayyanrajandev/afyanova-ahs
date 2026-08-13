<?php

namespace App\Modules\Patient\Domain\Repositories;

interface PatientRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByPatientNumber(string $patientNumber): bool;

    public function search(
        ?string $query,
        ?string $status,
        ?string $gender,
        ?string $region,
        ?string $district,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        ?string $registrationWindow = null,
        ?string $ageGroup = null,
        ?string $insuranceType = null,
    ): array;

    public function statusCounts(?string $query): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActiveHardDuplicateIdentifiers(
        ?string $nationalId,
        ?string $patientNumber = null,
        ?string $excludePatientId = null
    ): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActiveDuplicateCandidates(
        ?string $firstName,
        ?string $lastName,
        ?string $dateOfBirth,
        ?string $phone,
        ?string $gender,
        ?string $addressLine,
        ?string $excludePatientId = null
    ): array;
}

