<?php

namespace App\Modules\Patient\Domain\Repositories;

interface PatientAllergyRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function listByPatientId(
        string $patientId,
        ?string $status,
        int $page,
        int $perPage
    ): array;

    public function listActiveByPatientId(string $patientId): array;
}
