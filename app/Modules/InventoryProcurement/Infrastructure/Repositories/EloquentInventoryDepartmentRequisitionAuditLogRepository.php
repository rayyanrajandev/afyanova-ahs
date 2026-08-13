<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionAuditLogModel;

class EloquentInventoryDepartmentRequisitionAuditLogRepository implements InventoryDepartmentRequisitionAuditLogRepositoryInterface
{
    public function write(
        string $requisitionId,
        string $action,
        ?int $actorId = null,
        ?array $changes = null,
        ?array $metadata = null,
    ): void {
        $log = new InventoryDepartmentRequisitionAuditLogModel();
        $log->fill([
            'requisition_id' => $requisitionId,
            'action' => $action,
            'actor_type' => 'user',
            'actor_id' => $actorId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
        $log->save();
    }

    public function listByRequisitionId(string $requisitionId, int $page, int $perPage): array
    {
        $paginator = InventoryDepartmentRequisitionAuditLogModel::query()
            ->where('requisition_id', $requisitionId)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        return [
            'data' => array_map(
                static fn ($model) => $model->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
