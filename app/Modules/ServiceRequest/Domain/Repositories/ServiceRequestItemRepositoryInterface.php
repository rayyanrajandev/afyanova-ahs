<?php

namespace App\Modules\ServiceRequest\Domain\Repositories;

interface ServiceRequestItemRepositoryInterface
{
    public function createMany(string $serviceRequestId, array $items): void;

    public function findByServiceRequestId(string $serviceRequestId): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function deleteByServiceRequestId(string $serviceRequestId): void;
}
