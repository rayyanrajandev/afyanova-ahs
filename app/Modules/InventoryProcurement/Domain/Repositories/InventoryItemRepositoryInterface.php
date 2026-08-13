<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryItemRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findBestActiveMatchByCodeOrName(
        ?string $itemCode,
        ?string $itemName
    ): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByItemCode(string $itemCode, ?string $excludeId = null): bool;

    public function search(
        ?string $query,
        ?string $category,
        ?string $subcategory,
        ?string $requestingDepartmentId,
        ?string $stockState,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function stockAlertCounts(
        ?string $query,
        ?string $category,
        ?string $requestingDepartmentId = null
    ): array;

    /**
     * Find clinical_catalog_item_id values that are already linked to inventory items.
     *
     * @param list<string> $catalogItemIds
     * @return list<string>
     */
    public function findLinkedClinicalCatalogItemIds(array $catalogItemIds): array;

    /**
     * Bulk-load linked inventory items keyed by clinical_catalog_item_id.
     *
     * @param  list<string>  $catalogItemIds
     * @return array<string, array<string, mixed>>  Map of clinical_catalog_item_id => inventory item
     */
    public function listLinkedByClinicalCatalogIds(array $catalogItemIds): array;

    /**
     * @return list<string> All item codes currently in scope (for bulk uniqueness checks).
     */
    public function listExistingItemCodes(): array;
}
