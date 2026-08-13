<?php

namespace App\Modules\Pos\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Pos\Domain\Repositories\PosRegisterSessionRepositoryInterface;
use App\Modules\Pos\Infrastructure\Models\PosRegisterSessionModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentPosRegisterSessionRepository implements PosRegisterSessionRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $session = new PosRegisterSessionModel();
        $session->fill($attributes);
        $session->save();

        return $this->findById((string) $session->id) ?? $session->toArray();
    }

    public function findById(string $id, bool $lockForUpdate = false): ?array
    {
        $query = PosRegisterSessionModel::query()->with('register');
        $this->applyPlatformScopeIfEnabled($query);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $session = $query->find($id);

        return $session?->toArray();
    }

    public function findOpenByRegisterId(string $registerId, bool $lockForUpdate = false): ?array
    {
        $query = PosRegisterSessionModel::query()
            ->with('register')
            ->where('pos_register_id', $registerId)
            ->where('status', 'open');
        $this->applyPlatformScopeIfEnabled($query);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $session = $query->latest('opened_at')->first();

        return $session?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = PosRegisterSessionModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $session = $query->find($id);
        if ($session === null) {
            return null;
        }

        $session->fill($attributes);
        $session->save();

        return $this->findById($id) ?? $session->toArray();
    }

    public function existsBySessionNumber(string $sessionNumber): bool
    {
        return PosRegisterSessionModel::query()
            ->where('session_number', trim($sessionNumber))
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $registerId,
        ?string $status,
        int $page,
        int $perPage
    ): array {
        $queryBuilder = PosRegisterSessionModel::query()->with('register');
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('session_number', 'like', $like)
                        ->orWhereHas('register', function (Builder $registerQuery) use ($like): void {
                            $registerQuery
                                ->where('register_code', 'like', $like)
                                ->orWhere('register_name', 'like', $like);
                        });
                });
            })
            ->when($registerId, fn (Builder $builder, string $value) => $builder->where('pos_register_id', $value))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->orderByDesc('opened_at');

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (PosRegisterSessionModel $session): array => $session->toArray(),
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
