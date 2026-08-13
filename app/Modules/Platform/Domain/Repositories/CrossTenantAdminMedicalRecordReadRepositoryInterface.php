<?php

namespace App\Modules\Platform\Domain\Repositories;

interface CrossTenantAdminMedicalRecordReadRepositoryInterface
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function searchByTenantId(
        string $tenantId,
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $recordType,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;
}
