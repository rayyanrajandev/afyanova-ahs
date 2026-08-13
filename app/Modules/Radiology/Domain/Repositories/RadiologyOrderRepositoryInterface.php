<?php

namespace App\Modules\Radiology\Domain\Repositories;

interface RadiologyOrderRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function delete(string $id): bool;

    public function existsByOrderNumber(string $orderNumber): bool;

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $encounterId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $status,
        ?array $statuses,
        ?string $modality,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $modality,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
