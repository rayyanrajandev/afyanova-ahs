<?php

namespace App\Modules\Appointment\Domain\Repositories;

interface AppointmentAuditLogRepositoryInterface
{
    public function write(
        string $appointmentId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByAppointmentId(
        string $appointmentId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array;
}
