<?php

namespace App\Modules\InpatientWard\Domain\Repositories;

interface InpatientWardCensusRepositoryInterface
{
    public function searchCurrentInpatients(
        ?string $query,
        ?string $ward,
        int $page,
        int $perPage
    ): array;

    public function findCurrentAdmissionById(string $admissionId): ?array;
}
