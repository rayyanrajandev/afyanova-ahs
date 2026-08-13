<?php

namespace App\Modules\Appointment\Domain\Repositories;

interface AppointmentReferralRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByAppointmentAndId(string $appointmentId, string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByReferralNumber(string $referralNumber): bool;

    public function searchByAppointment(
        string $appointmentId,
        ?string $query,
        ?string $referralType,
        ?string $priority,
        ?string $status,
        ?string $targetFacilityCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCountsByAppointment(
        string $appointmentId,
        ?string $query,
        ?string $referralType,
        ?string $priority,
        ?string $targetFacilityCode,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;

    public function searchNetwork(
        ?string $query,
        ?string $referralType,
        ?string $priority,
        ?string $status,
        ?string $targetFacilityCode,
        ?string $networkMode,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCountsNetwork(
        ?string $query,
        ?string $referralType,
        ?string $priority,
        ?string $targetFacilityCode,
        ?string $networkMode,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
