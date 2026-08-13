<?php

namespace App\Modules\InventoryProcurement\Application\Services;

use App\Modules\InventoryProcurement\Infrastructure\Models\DepartmentItemCatalogModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;

class DepartmentItemCatalogService
{
    /**
     * Get item IDs explicitly assigned to a department via the catalog.
     *
     * @return array<int, string>|null Null when no explicit catalog exists (use fallback).
     */
    public function catalogItemIdsForDepartment(?string $departmentId): ?array
    {
        if ($departmentId === null || trim($departmentId) === '') {
            return null;
        }

        $assigned = DepartmentItemCatalogModel::query()
            ->where('department_id', $departmentId)
            ->pluck('item_id')
            ->toArray();

        return count($assigned) > 0 ? $assigned : null;
    }

    /**
     * Returns true when the department has any explicit catalog assignments.
     */
    public function hasExplicitCatalog(string $departmentId): bool
    {
        return DepartmentItemCatalogModel::query()
            ->where('department_id', $departmentId)
            ->exists();
    }

    /**
     * Assign items to a department's catalog (replaces existing).
     *
     * @param  array<int, string>  $itemIds
     */
    public function assignItemsToDepartment(string $departmentId, array $itemIds, ?string $createdByUserId): void
    {
        DepartmentItemCatalogModel::query()
            ->where('department_id', $departmentId)
            ->delete();

        $records = [];
        foreach (array_unique($itemIds) as $itemId) {
            if (trim($itemId) === '') {
                continue;
            }

            $records[] = [
                'department_id' => $departmentId,
                'item_id' => $itemId,
                'created_by' => $createdByUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (count($records) > 0) {
            DepartmentItemCatalogModel::query()->insert($records);
        }
    }

    /**
     * Get catalog items with their names/codes for the management UI.
     *
     * @return array<int, array{id: string, itemId: string, itemName: string, itemCode: string|null}>
     */
    public function catalogItemsWithDetails(string $departmentId): array
    {
        $assigned = DepartmentItemCatalogModel::query()
            ->where('department_id', $departmentId)
            ->pluck('item_id')
            ->toArray();

        if (count($assigned) === 0) {
            return [];
        }

        $items = InventoryItemModel::query()
            ->whereIn('id', $assigned)
            ->select(['id', 'item_name', 'item_code', 'category'])
            ->get()
            ->keyBy('id');

        $results = [];
        foreach ($assigned as $itemId) {
            $item = $items->get($itemId);
            $results[] = [
                'id' => $itemId,
                'itemId' => $itemId,
                'itemName' => $item?->item_name ?? 'Unknown',
                'itemCode' => $item?->item_code ?? null,
                'category' => $item?->category ?? null,
            ];
        }

        return $results;
    }

    /**
     * Get the preferred (default) warehouse for a department.
     */
    public function preferredWarehouseId(?string $departmentId): ?string
    {
        if ($departmentId === null || trim($departmentId) === '') {
            return null;
        }

        $department = DepartmentModel::query()->find($departmentId);

        return $department?->default_warehouse_id;
    }

    /**
     * Set the default warehouse for a department.
     */
    public function setPreferredWarehouse(string $departmentId, ?string $warehouseId): void
    {
        DepartmentModel::query()
            ->where('id', $departmentId)
            ->update(['default_warehouse_id' => $warehouseId]);
    }
}