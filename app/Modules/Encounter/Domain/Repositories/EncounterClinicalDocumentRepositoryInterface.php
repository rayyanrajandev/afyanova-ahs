<?php

namespace App\Modules\Encounter\Domain\Repositories;

interface EncounterClinicalDocumentRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByIdForEncounter(string $encounterId, string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function searchByEncounterId(
        string $encounterId,
        ?string $query,
        ?string $documentType,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array;
}
