<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordSignerAttestationRepositoryInterface;

class ListMedicalRecordSignerAttestationsUseCase
{
    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecordRepository,
        private readonly MedicalRecordSignerAttestationRepositoryInterface $medicalRecordSignerAttestationRepository,
    ) {}

    public function execute(string $medicalRecordId, array $filters): ?array
    {
        $record = $this->medicalRecordRepository->findById($medicalRecordId);
        if (! $record) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        return $this->medicalRecordSignerAttestationRepository->listByMedicalRecordId(
            medicalRecordId: $medicalRecordId,
            page: $page,
            perPage: $perPage,
        );
    }
}
