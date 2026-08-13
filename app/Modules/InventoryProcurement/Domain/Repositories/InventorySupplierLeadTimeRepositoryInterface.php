<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventorySupplierLeadTimeRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function listBySupplier(string $supplierId, int $page, int $perPage): array;

    public function listByItem(string $itemId, int $page, int $perPage): array;

    public function averageLeadTime(string $supplierId, ?string $itemId = null): ?float;

    public function averageFulfillmentRate(string $supplierId, ?string $itemId = null): ?float;

    /**
     * Record delivery and calculate actuals from order_date → actual_delivery_date.
     */
    public function recordDelivery(string $id, array $deliveryData): ?array;
}
