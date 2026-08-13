<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryDispensingClaimLinkRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function search(
        ?string $patientId,
        ?string $claimStatus,
        ?string $insuranceClaimId,
        ?string $query,
        int $page,
        int $perPage
    ): array;

    public function listByInsuranceClaim(string $insuranceClaimId): array;

    public function listByPatient(string $patientId, int $page, int $perPage): array;
}
