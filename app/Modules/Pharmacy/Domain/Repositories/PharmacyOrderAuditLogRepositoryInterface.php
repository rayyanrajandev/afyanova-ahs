<?php

namespace App\Modules\Pharmacy\Domain\Repositories;

interface PharmacyOrderAuditLogRepositoryInterface
{
    public function write(
        string $pharmacyOrderId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByPharmacyOrderId(
        string $pharmacyOrderId,
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
