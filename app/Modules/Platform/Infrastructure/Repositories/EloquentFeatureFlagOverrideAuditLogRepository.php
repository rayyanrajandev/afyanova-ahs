<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideAuditLogRepositoryInterface;
use App\Modules\Platform\Infrastructure\Models\FeatureFlagOverrideAuditLogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentFeatureFlagOverrideAuditLogRepository implements FeatureFlagOverrideAuditLogRepositoryInterface
{
    public function write(
        string $featureFlagOverrideId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void {
        FeatureFlagOverrideAuditLogModel::query()->create([
            'feature_flag_override_id' => $featureFlagOverrideId,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listByFeatureFlagOverrideId(string $featureFlagOverrideId, int $page, int $perPage): array
    {
        $paginator = FeatureFlagOverrideAuditLogModel::query()
            ->where('feature_flag_override_id', $featureFlagOverrideId)
            ->orderByDesc('created_at')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (FeatureFlagOverrideAuditLogModel $log): array => $log->toArray(),
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
