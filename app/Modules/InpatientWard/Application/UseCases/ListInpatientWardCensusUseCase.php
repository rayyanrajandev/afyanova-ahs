<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCensusRepositoryInterface;

class ListInpatientWardCensusUseCase
{
    public function __construct(private readonly InpatientWardCensusRepositoryInterface $censusRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;
        $ward = isset($filters['ward']) ? trim((string) $filters['ward']) : null;
        $ward = $ward === '' ? null : $ward;

        return $this->censusRepository->searchCurrentInpatients(
            query: $query,
            ward: $ward,
            page: $page,
            perPage: $perPage,
        );
    }
}
