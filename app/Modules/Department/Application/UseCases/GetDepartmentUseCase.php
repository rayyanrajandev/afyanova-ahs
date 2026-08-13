<?php

namespace App\Modules\Department\Application\UseCases;

use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;

class GetDepartmentUseCase
{
    public function __construct(private readonly DepartmentRepositoryInterface $departmentRepository) {}

    public function execute(string $id): ?array
    {
        return $this->departmentRepository->findById($id);
    }
}

