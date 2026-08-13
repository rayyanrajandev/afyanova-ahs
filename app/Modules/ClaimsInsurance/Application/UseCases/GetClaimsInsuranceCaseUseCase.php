<?php

namespace App\Modules\ClaimsInsurance\Application\UseCases;

use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseRepositoryInterface;

class GetClaimsInsuranceCaseUseCase
{
    public function __construct(private readonly ClaimsInsuranceCaseRepositoryInterface $claimsInsuranceCaseRepository) {}

    public function execute(string $id): ?array
    {
        return $this->claimsInsuranceCaseRepository->findById($id);
    }
}
