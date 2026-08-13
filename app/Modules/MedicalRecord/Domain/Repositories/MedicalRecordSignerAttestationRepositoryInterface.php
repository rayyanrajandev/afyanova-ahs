<?php

namespace App\Modules\MedicalRecord\Domain\Repositories;

interface MedicalRecordSignerAttestationRepositoryInterface
{
    public function create(
        string $medicalRecordId,
        int $attestedByUserId,
        string $attestationNote,
    ): array;

    public function listByMedicalRecordId(
        string $medicalRecordId,
        int $page,
        int $perPage,
    ): array;
}
