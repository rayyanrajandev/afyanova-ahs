<?php

namespace App\Modules\Department\Domain\Repositories;

interface DepartmentRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByCodeInScope(
        string $code,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool;

    public function search(
        ?string $query,
        ?string $status,
        ?string $serviceType,
        ?int $managerUserId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $serviceType,
        ?int $managerUserId,
    ): array;

    public function listAppointmentableOptions(): array;

    public function findActiveByName(string $name): ?array;

    /**
     * The department a walk-in lands in when nobody has routed them yet.
     *
     * Falls back through: the department flagged as the walk-in default, then
     * any active appointmentable clinical department. Null only when a facility
     * has no routable clinic at all, in which case the visit stays unrouted
     * rather than being pointed at something arbitrary.
     *
     * @return array<string, mixed>|null
     */
    public function findDefaultWalkInDepartment(): ?array;

    /**
     * The department an emergency arrival is routed to.
     *
     * Resolved by `service_type = 'Emergency'` rather than by a magic code, so a
     * facility can name its emergency unit whatever it likes. Null when a
     * facility has no emergency department, in which case the visit keeps the
     * legacy 'Emergency' label only — enough for
     * EncounterResolverService::deriveEncounterType() to still type the
     * encounter correctly.
     *
     * @return array<string, mixed>|null
     */
    public function findEmergencyDepartment(): ?array;
}

