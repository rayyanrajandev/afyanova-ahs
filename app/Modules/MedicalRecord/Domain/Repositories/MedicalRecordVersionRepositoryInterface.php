<?php

namespace App\Modules\MedicalRecord\Domain\Repositories;

interface MedicalRecordVersionRepositoryInterface
{
    public function create(
        string $medicalRecordId,
        array $snapshot,
        array $changedFields,
        ?int $createdByUserId,
    ): array;

    public function listByMedicalRecordId(
        string $medicalRecordId,
        int $page,
        int $perPage,
    ): array;

    public function findById(string $id): ?array;

    public function findLatestByMedicalRecordId(string $medicalRecordId): ?array;

    public function findByMedicalRecordAndVersionNumber(string $medicalRecordId, int $versionNumber): ?array;
}
