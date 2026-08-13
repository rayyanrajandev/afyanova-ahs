<?php

namespace App\Modules\ClinicalProcedure\Infrastructure\Repositories;

use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderAuditLogRepositoryInterface;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderAuditLogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentClinicalProcedureOrderAuditLogRepository implements ClinicalProcedureOrderAuditLogRepositoryInterface
{
    public function write(
        string $clinicalProcedureOrderId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void {
        ClinicalProcedureOrderAuditLogModel::query()->create([
            'clinical_procedure_order_id' => $clinicalProcedureOrderId,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listByClinicalProcedureOrderId(
        string $clinicalProcedureOrderId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array
    {
        $paginator = ClinicalProcedureOrderAuditLogModel::query()
            ->where('clinical_procedure_order_id', $clinicalProcedureOrderId)
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
                static fn (ClinicalProcedureOrderAuditLogModel $log): array => $log->toArray(),
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
