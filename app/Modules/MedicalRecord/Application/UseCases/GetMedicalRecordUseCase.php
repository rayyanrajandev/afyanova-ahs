<?php

namespace App\Modules\MedicalRecord\Application\UseCases;

use App\Modules\MedicalRecord\Domain\Repositories\MedicalRecordRepositoryInterface;

class GetMedicalRecordUseCase
{
    public function __construct(private readonly MedicalRecordRepositoryInterface $medicalRecordRepository) {}

    public function execute(string $id): ?array
    {
        return $this->medicalRecordRepository->findById($id);
    }
}
