<?php

namespace App\Modules\Encounter\Infrastructure\Repositories;

use App\Modules\Encounter\Domain\Repositories\EncounterClinicalDocumentRepositoryInterface;
use App\Modules\Encounter\Infrastructure\Models\EncounterClinicalDocumentModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentEncounterClinicalDocumentRepository implements EncounterClinicalDocumentRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $document = new EncounterClinicalDocumentModel();
        $document->fill($attributes);
        $document->save();

        return $document->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = EncounterClinicalDocumentModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $document = $query->find($id);

        return $document?->toArray();
    }

    public function findByIdForEncounter(string $encounterId, string $id): ?array
    {
        $query = EncounterClinicalDocumentModel::query()
            ->where('encounter_id', $encounterId)
            ->where('id', $id);
        $this->applyTenantScopeIfEnabled($query);
        $document = $query->first();

        return $document?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = EncounterClinicalDocumentModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $document = $query->find($id);
        if (! $document) {
            return null;
        }

        $document->fill($attributes);
        $document->save();

        return $document->toArray();
    }

    public function searchByEncounterId(
        string $encounterId,
        ?string $query,
        ?string $documentType,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array {
        $sortBy = in_array($sortBy, ['title', 'document_type', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'created_at';

        $queryBuilder = EncounterClinicalDocumentModel::query()
            ->where('encounter_id', $encounterId);
        $this->applyTenantScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('title', 'like', $like)
                        ->orWhere('document_type', 'like', $like)
                        ->orWhere('original_filename', 'like', $like);
                });
            })
            ->when($documentType, fn (Builder $builder, string $value) => $builder->where('document_type', $value))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (EncounterClinicalDocumentModel $document): array => $document->toArray(),
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

    private function applyTenantScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply(
            $query,
            tenantColumn: 'tenant_id',
            facilityColumn: 'facility_id',
        );
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
