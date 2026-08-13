<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogRepositoryInterface;
use App\Modules\Platform\Infrastructure\Models\CrossTenantAdminAuditLogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCrossTenantAdminAuditLogRepository implements CrossTenantAdminAuditLogRepositoryInterface
{
    public function write(
        string $action,
        string $operationType,
        ?int $actorId,
        ?string $targetTenantId,
        ?string $targetTenantCode,
        ?string $targetResourceType,
        ?string $targetResourceId,
        string $outcome,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        CrossTenantAdminAuditLogModel::query()->create([
            'action' => $action,
            'operation_type' => $operationType,
            'actor_id' => $actorId,
            'target_tenant_id' => $targetTenantId,
            'target_tenant_code' => $targetTenantCode,
            'target_resource_type' => $targetResourceType,
            'target_resource_id' => $targetResourceId,
            'outcome' => $outcome,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        $paginator = CrossTenantAdminAuditLogModel::query()
            ->when(
                isset($filters['action']) && $filters['action'] !== null,
                fn (Builder $builder) => $builder->where('action', (string) $filters['action'])
            )
            ->when(
                isset($filters['operationType']) && $filters['operationType'] !== null,
                fn (Builder $builder) => $builder->where('operation_type', (string) $filters['operationType'])
            )
            ->when(
                isset($filters['targetTenantCode']) && $filters['targetTenantCode'] !== null,
                fn (Builder $builder) => $builder->where('target_tenant_code', strtoupper((string) $filters['targetTenantCode']))
            )
            ->when(
                isset($filters['targetResourceType']) && $filters['targetResourceType'] !== null,
                fn (Builder $builder) => $builder->where('target_resource_type', (string) $filters['targetResourceType'])
            )
            ->when(
                isset($filters['outcome']) && $filters['outcome'] !== null,
                fn (Builder $builder) => $builder->where('outcome', (string) $filters['outcome'])
            )
            ->when(
                isset($filters['actorId']) && $filters['actorId'] !== null,
                fn (Builder $builder) => $builder->where('actor_id', (int) $filters['actorId'])
            )
            ->orderByDesc('created_at')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function toPagedResult(LengthAwarePaginator $paginator, array $filters): array
    {
        return [
            'data' => array_map(
                static fn (CrossTenantAdminAuditLogModel $log): array => $log->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'filters' => [
                    'action' => $filters['action'] ?? null,
                    'operationType' => $filters['operationType'] ?? null,
                    'targetTenantCode' => isset($filters['targetTenantCode']) && $filters['targetTenantCode'] !== null
                        ? strtoupper((string) $filters['targetTenantCode'])
                        : null,
                    'targetResourceType' => $filters['targetResourceType'] ?? null,
                    'outcome' => $filters['outcome'] ?? null,
                    'actorId' => $filters['actorId'] ?? null,
                ],
            ],
        ];
    }
}
