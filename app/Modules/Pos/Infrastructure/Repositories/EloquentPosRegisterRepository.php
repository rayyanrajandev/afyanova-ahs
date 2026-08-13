<?php

namespace App\Modules\Pos\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Pos\Domain\Repositories\PosRegisterRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosRegisterSessionStatus;
use App\Modules\Pos\Infrastructure\Models\PosRegisterModel;
use App\Modules\Pos\Infrastructure\Models\PosRegisterSessionModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentPosRegisterRepository implements PosRegisterRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $register = new PosRegisterModel();
        $register->fill($attributes);
        $register->save();

        return $this->attachCurrentOpenSession($register->toArray());
    }

    public function findById(string $id): ?array
    {
        $query = PosRegisterModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $register = $query->find($id);

        return $register === null ? null : $this->attachCurrentOpenSession($register->toArray());
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = PosRegisterModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $register = $query->find($id);
        if ($register === null) {
            return null;
        }

        $register->fill($attributes);
        $register->save();

        return $this->attachCurrentOpenSession($register->toArray());
    }

    public function existsByRegisterCodeInScope(
        string $registerCode,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool {
        $query = PosRegisterModel::query()
            ->whereRaw('LOWER(register_code) = ?', [strtolower(trim($registerCode))]);

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        if ($facilityId === null) {
            $query->whereNull('facility_id');
        } else {
            $query->where('facility_id', $facilityId);
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function search(
        ?string $query,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['register_code', 'register_name', 'location', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'register_name';

        $queryBuilder = PosRegisterModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('register_code', 'like', $like)
                        ->orWhere('register_name', 'like', $like)
                        ->orWhere('location', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        $registers = array_map(
            static fn (PosRegisterModel $register): array => $register->toArray(),
            $paginator->items(),
        );

        return $this->toSearchResult($paginator, $this->attachCurrentOpenSessions($registers));
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

    /**
     * @param array<string, mixed> $register
     * @return array<string, mixed>
     */
    private function attachCurrentOpenSession(array $register): array
    {
        $registerId = (string) ($register['id'] ?? '');
        if ($registerId === '') {
            $register['current_open_session'] = null;

            return $register;
        }

        $session = PosRegisterSessionModel::query()
            ->where('pos_register_id', $registerId)
            ->where('status', PosRegisterSessionStatus::OPEN->value)
            ->latest('opened_at')
            ->first();

        $register['current_open_session'] = $session?->toArray();

        return $register;
    }

    /**
     * @param array<int, array<string, mixed>> $registers
     * @return array<int, array<string, mixed>>
     */
    private function attachCurrentOpenSessions(array $registers): array
    {
        $registerIds = array_values(array_filter(array_map(
            static fn (array $register): string => (string) ($register['id'] ?? ''),
            $registers,
        )));

        if ($registerIds === []) {
            return $registers;
        }

        $sessions = PosRegisterSessionModel::query()
            ->whereIn('pos_register_id', $registerIds)
            ->where('status', PosRegisterSessionStatus::OPEN->value)
            ->get()
            ->keyBy('pos_register_id');

        foreach ($registers as $index => $register) {
            $registerId = (string) ($register['id'] ?? '');
            $registers[$index]['current_open_session'] = $sessions->has($registerId)
                ? $sessions->get($registerId)?->toArray()
                : null;
        }

        return $registers;
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @return array<string, mixed>
     */
    private function toSearchResult(LengthAwarePaginator $paginator, array $data): array
    {
        return [
            'data' => $data,
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }
}
