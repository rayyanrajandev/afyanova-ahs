<?php

namespace App\Modules\EmergencyTriage\Domain\Repositories;

interface EmergencyTriageCaseRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByCaseNumber(string $caseNumber): bool;

    public function existsByAppointmentId(string $appointmentId): bool;

    public function findByAppointmentId(string $appointmentId): ?array;

    public function findActiveForPatient(string $patientId): ?array;

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $triageLevel,
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
        ?string $triageLevel,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
