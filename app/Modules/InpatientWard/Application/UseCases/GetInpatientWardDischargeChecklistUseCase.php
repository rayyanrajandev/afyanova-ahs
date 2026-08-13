<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistRepositoryInterface;

class GetInpatientWardDischargeChecklistUseCase
{
    public function __construct(
        private readonly InpatientWardDischargeChecklistRepositoryInterface $checklistRepository,
    ) {}

    public function execute(string $id): ?array
    {
        return $this->checklistRepository->findById(trim($id));
    }
}
