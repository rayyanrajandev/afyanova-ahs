<?php

namespace App\Modules\Appointment\Domain\Repositories;

interface AppointmentReferralAuditLogRepositoryInterface
{
    public function write(
        string $referralId,
        string $appointmentId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByReferralId(
        string $referralId,
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

