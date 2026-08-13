<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionLineRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionLineModel;

class EloquentInventoryDepartmentRequisitionLineRepository implements InventoryDepartmentRequisitionLineRepositoryInterface
{
    public function create(array $attributes): array
    {
        $model = new InventoryDepartmentRequisitionLineModel();
        $model->fill($attributes);
        $model->save();

        return $model->toArray();
    }

    public function findById(string $id): ?array
    {
        return InventoryDepartmentRequisitionLineModel::query()->find($id)?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $model = InventoryDepartmentRequisitionLineModel::query()->find($id);
        if (! $model) {
            return null;
        }

        $model->fill($attributes);
        $model->save();

        return $model->toArray();
    }

    public function listByRequisitionId(string $requisitionId): array
    {
        return InventoryDepartmentRequisitionLineModel::query()
            ->leftJoin('inventory_items', 'inventory_items.id', '=', 'inventory_department_requisition_lines.item_id')
            ->where('requisition_id', $requisitionId)
            ->select([
                'inventory_department_requisition_lines.*',
                'inventory_items.item_code as item_code',
                'inventory_items.item_name as item_name',
                'inventory_items.category as item_category',
                'inventory_items.subcategory as item_subcategory',
                'inventory_items.current_stock as item_current_stock',
            ])
            ->get()
            ->map(fn ($model) => $model->toArray())
            ->all();
    }

    public function deleteByRequisitionId(string $requisitionId): int
    {
        return InventoryDepartmentRequisitionLineModel::query()
            ->where('requisition_id', $requisitionId)
            ->delete();
    }
}
