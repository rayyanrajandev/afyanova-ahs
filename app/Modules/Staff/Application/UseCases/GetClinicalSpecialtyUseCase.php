<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;

class GetClinicalSpecialtyUseCase
{
    public function __construct(private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository) {}

    public function execute(string $id): ?array
    {
        return $this->clinicalSpecialtyRepository->findById($id);
    }
}

