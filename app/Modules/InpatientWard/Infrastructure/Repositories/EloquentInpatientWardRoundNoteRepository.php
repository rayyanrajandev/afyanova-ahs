<?php

namespace App\Modules\InpatientWard\Infrastructure\Repositories;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardRoundNoteRepositoryInterface;
use App\Modules\InpatientWard\Infrastructure\Models\InpatientWardRoundNoteModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInpatientWardRoundNoteRepository implements InpatientWardRoundNoteRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $note = new InpatientWardRoundNoteModel();
        $note->fill($attributes);
        $note->save();

        return $note->fresh()?->toArray() ?? $note->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InpatientWardRoundNoteModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query->whereKey($id)->first()?->toArray();
    }

    public function acknowledge(string $id, array $attributes): ?array
    {
        $query = InpatientWardRoundNoteModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $note = $query->whereKey($id)->first();
        if (! $note) {
            return null;
        }

        $note->fill($attributes);
        $note->save();

        return $note->fresh()?->toArray() ?? $note->toArray();
    }

    public function search(
        ?string $query,
        ?string $admissionId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['rounded_at', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'rounded_at';

        $queryBuilder = InpatientWardRoundNoteModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('round_note', 'like', $like)
                        ->orWhere('care_plan', 'like', $like)
                        ->orWhere('handoff_notes', 'like', $like)
                        ->orWhere('shift_label', 'like', $like);
                });
            })
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId))
            ->orderBy($sortBy, $sortDirection);

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
                static fn (InpatientWardRoundNoteModel $note): array => $note->toArray(),
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
