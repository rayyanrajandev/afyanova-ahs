<?php

namespace App\Modules\Laboratory\Infrastructure\Repositories;

use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderAuditLogRepositoryInterface;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderAuditLogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentLaboratoryOrderAuditLogRepository implements LaboratoryOrderAuditLogRepositoryInterface
{
    public function write(
        string $laboratoryOrderId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void {
        LaboratoryOrderAuditLogModel::query()->create([
            'laboratory_order_id' => $laboratoryOrderId,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listByLaboratoryOrderId(
        string $laboratoryOrderId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array {
        $paginator = LaboratoryOrderAuditLogModel::query()
            ->where('laboratory_order_id', $laboratoryOrderId)
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
                static fn (LaboratoryOrderAuditLogModel $log): array => $log->toArray(),
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
