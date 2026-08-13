<?php

namespace App\Modules\Admission\Application\UseCases;

use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;

class GetAdmissionUseCase
{
    public function __construct(private readonly AdmissionRepositoryInterface $admissionRepository) {}

    public function execute(string $id): ?array
    {
        return $this->admissionRepository->findById($id);
    }
}
