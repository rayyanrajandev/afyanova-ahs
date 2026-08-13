<?php

namespace App\Modules\TheatreProcedure\Infrastructure\Repositories;

use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureResourceAllocationAuditLogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentTheatreProcedureResourceAllocationAuditLogRepository implements TheatreProcedureResourceAllocationAuditLogRepositoryInterface
{
    public function write(
        string $allocationId,
        string $theatreProcedureId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void {
        TheatreProcedureResourceAllocationAuditLogModel::query()->create([
            'theatre_procedure_resource_allocation_id' => $allocationId,
            'theatre_procedure_id' => $theatreProcedureId,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listByAllocationId(
        string $allocationId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array {
        $paginator = TheatreProcedureResourceAllocationAuditLogModel::query()
            ->where('theatre_procedure_resource_allocation_id', $allocationId)
            ->when($query, fn (Builder $builder, string $value) => $builder->whereRaw('LOWER(action) LIKE ?', ['%'.strtolower($value).'%']))
            ->when($action, fn (Builder $builder, string $value) => $builder->where('action', $value))
            ->when($actorType === 'system', fn (Builder $builder) => $builder->whereNull('actor_id'))
            ->when($actorType === 'user', fn (Builder $builder) => $builder->whereNotNull('actor_id'))
            ->when($actorId !== null, fn (Builder $builder) => $builder->where('actor_id', $actorId))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '<=', $value))
            ->orderByDesc('created_at')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator);
    }

    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (TheatreProcedureResourceAllocationAuditLogModel $log): array => $log->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }
}
