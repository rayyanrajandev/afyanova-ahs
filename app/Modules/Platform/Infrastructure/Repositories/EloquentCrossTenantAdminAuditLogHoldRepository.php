<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogHoldRepositoryInterface;
use App\Modules\Platform\Infrastructure\Models\CrossTenantAdminAuditLogHoldModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCrossTenantAdminAuditLogHoldRepository implements CrossTenantAdminAuditLogHoldRepositoryInterface
{
    public function create(array $attributes): array
    {
        $hold = new CrossTenantAdminAuditLogHoldModel();
        $hold->fill($attributes);
        $hold->save();

        return $hold->toArray();
    }

    public function findById(string $id): ?array
    {
        return CrossTenantAdminAuditLogHoldModel::query()->find($id)?->toArray();
    }

    public function findByHoldCode(string $holdCode): ?array
    {
        return CrossTenantAdminAuditLogHoldModel::query()
            ->where('hold_code', strtoupper(trim($holdCode)))
            ->first()
            ?->toArray();
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        $sortMap = [
            'createdAt' => 'created_at',
            'releasedAt' => 'released_at',
        ];
        $sortBy = $sortMap[(string) ($filters['sortBy'] ?? 'createdAt')] ?? 'created_at';
        $sortDir = strtolower((string) ($filters['sortDir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $paginator = CrossTenantAdminAuditLogHoldModel::query()
            ->when(
                isset($filters['q']) && $filters['q'] !== null,
                function (Builder $builder) use ($filters): void {
                    $query = (string) $filters['q'];
                    $like = '%'.$query.'%';

                    $builder->where(function (Builder $search) use ($like): void {
                        $search
                            ->where('hold_code', 'like', $like)
                            ->orWhere('reason', 'like', $like);
                    });
                }
            )
            ->when(
                isset($filters['holdCode']) && $filters['holdCode'] !== null,
                fn (Builder $builder) => $builder->where('hold_code', strtoupper((string) $filters['holdCode']))
            )
            ->when(
                isset($filters['targetTenantCode']) && $filters['targetTenantCode'] !== null,
                fn (Builder $builder) => $builder->where('target_tenant_code', strtoupper((string) $filters['targetTenantCode']))
            )
            ->when(
                isset($filters['action']) && $filters['action'] !== null,
                fn (Builder $builder) => $builder->where('action', (string) $filters['action'])
            )
            ->when(
                isset($filters['approvalCaseReference']) && $filters['approvalCaseReference'] !== null,
                fn (Builder $builder) => $builder->where('approval_case_reference', (string) $filters['approvalCaseReference'])
            )
            ->when(
                isset($filters['approvedByUserId']) && $filters['approvedByUserId'] !== null,
                fn (Builder $builder) => $builder->where('approved_by_user_id', (int) $filters['approvedByUserId'])
            )
            ->when(
                isset($filters['createdByUserId']) && $filters['createdByUserId'] !== null,
                fn (Builder $builder) => $builder->where('created_by_user_id', (int) $filters['createdByUserId'])
            )
            ->when(
                isset($filters['releaseCaseReference']) && $filters['releaseCaseReference'] !== null,
                fn (Builder $builder) => $builder->where('release_case_reference', (string) $filters['releaseCaseReference'])
            )
            ->when(
                isset($filters['releaseApprovedByUserId']) && $filters['releaseApprovedByUserId'] !== null,
                fn (Builder $builder) => $builder->where('release_approved_by_user_id', (int) $filters['releaseApprovedByUserId'])
            )
            ->when(
                isset($filters['releasedByUserId']) && $filters['releasedByUserId'] !== null,
                fn (Builder $builder) => $builder->where('released_by_user_id', (int) $filters['releasedByUserId'])
            )
            ->when(
                isset($filters['createdFrom']) && $filters['createdFrom'] !== null,
                fn (Builder $builder) => $builder->where('created_at', '>=', (string) $filters['createdFrom'])
            )
            ->when(
                isset($filters['createdTo']) && $filters['createdTo'] !== null,
                fn (Builder $builder) => $builder->where('created_at', '<=', (string) $filters['createdTo'])
            )
            ->when(
                isset($filters['releasedFrom']) && $filters['releasedFrom'] !== null,
                fn (Builder $builder) => $builder->where('released_at', '>=', (string) $filters['releasedFrom'])
            )
            ->when(
                isset($filters['releasedTo']) && $filters['releasedTo'] !== null,
                fn (Builder $builder) => $builder->where('released_at', '<=', (string) $filters['releasedTo'])
            )
            ->when(
                array_key_exists('isActive', $filters) && $filters['isActive'] !== null,
                fn (Builder $builder) => $builder->where('is_active', (bool) $filters['isActive'])
            )
            ->orderBy($sortBy, $sortDir)
            ->orderByDesc('id')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator, $filters);
    }

    public function release(string $id, array $attributes): ?array
    {
        $hold = CrossTenantAdminAuditLogHoldModel::query()->find($id);
        if (! $hold) {
            return null;
        }

        $hold->fill($attributes);
        $hold->save();

        return $hold->toArray();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function toPagedResult(LengthAwarePaginator $paginator, array $filters): array
    {
        return [
            'data' => array_map(
                static fn (CrossTenantAdminAuditLogHoldModel $hold): array => $hold->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'filters' => [
                    'q' => $filters['q'] ?? null,
                    'holdCode' => isset($filters['holdCode']) && $filters['holdCode'] !== null
                        ? strtoupper((string) $filters['holdCode'])
                        : null,
                    'targetTenantCode' => isset($filters['targetTenantCode']) && $filters['targetTenantCode'] !== null
                        ? strtoupper((string) $filters['targetTenantCode'])
                        : null,
                    'action' => $filters['action'] ?? null,
                    'approvalCaseReference' => $filters['approvalCaseReference'] ?? null,
                    'approvedByUserId' => $filters['approvedByUserId'] ?? null,
                    'createdByUserId' => $filters['createdByUserId'] ?? null,
                    'releaseCaseReference' => $filters['releaseCaseReference'] ?? null,
                    'releaseApprovedByUserId' => $filters['releaseApprovedByUserId'] ?? null,
                    'releasedByUserId' => $filters['releasedByUserId'] ?? null,
                    'createdFrom' => $filters['createdFrom'] ?? null,
                    'createdTo' => $filters['createdTo'] ?? null,
                    'releasedFrom' => $filters['releasedFrom'] ?? null,
                    'releasedTo' => $filters['releasedTo'] ?? null,
                    'isActive' => array_key_exists('isActive', $filters) ? $filters['isActive'] : null,
                    'sortBy' => in_array(($filters['sortBy'] ?? null), ['createdAt', 'releasedAt'], true)
                        ? $filters['sortBy']
                        : 'createdAt',
                    'sortDir' => (($filters['sortDir'] ?? null) === 'asc') ? 'asc' : 'desc',
                ],
            ],
        ];
    }
}
