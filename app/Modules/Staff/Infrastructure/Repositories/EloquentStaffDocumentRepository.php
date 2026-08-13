<?php

namespace App\Modules\Staff\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Infrastructure\Models\StaffDocumentModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentStaffDocumentRepository implements StaffDocumentRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $document = new StaffDocumentModel();
        $document->fill($attributes);
        $document->save();

        return $document->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = StaffDocumentModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $document = $query->find($id);

        return $document?->toArray();
    }

    public function findByIdForStaffProfile(string $staffProfileId, string $id): ?array
    {
        $query = StaffDocumentModel::query()
            ->where('staff_profile_id', $staffProfileId)
            ->where('id', $id);
        $this->applyTenantScopeIfEnabled($query);
        $document = $query->first();

        return $document?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = StaffDocumentModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $document = $query->find($id);
        if (! $document) {
            return null;
        }

        $document->fill($attributes);
        $document->save();

        return $document->toArray();
    }

    public function searchByStaffProfileId(
        string $staffProfileId,
        ?string $query,
        ?string $documentType,
        ?string $status,
        ?string $verificationStatus,
        ?string $expiresFrom,
        ?string $expiresTo,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array {
        $sortBy = in_array($sortBy, ['title', 'document_type', 'status', 'verification_status', 'expires_at', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'created_at';

        $queryBuilder = StaffDocumentModel::query()
            ->where('staff_profile_id', $staffProfileId);
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
            ->when($verificationStatus, fn (Builder $builder, string $value) => $builder->where('verification_status', $value))
            ->when($expiresFrom, fn (Builder $builder, string $value) => $builder->where('expires_at', '>=', $value))
            ->when($expiresTo, fn (Builder $builder, string $value) => $builder->where('expires_at', '<=', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function listByStaffProfileIds(array $staffProfileIds, ?string $status = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $staffProfileIds),
            static fn (string $value): bool => $value !== '',
        )));

        if ($ids === []) {
            return [];
        }

        $query = StaffDocumentModel::query()
            ->whereIn('staff_profile_id', $ids)
            ->orderBy('staff_profile_id')
            ->orderBy('expires_at')
            ->orderByDesc('updated_at');
        $this->applyTenantScopeIfEnabled($query);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $grouped = [];
        foreach ($query->get() as $document) {
            $payload = $document->toArray();
            $staffProfileId = trim((string) ($payload['staff_profile_id'] ?? ''));

            if ($staffProfileId === '') {
                continue;
            }

            $grouped[$staffProfileId] ??= [];
            $grouped[$staffProfileId][] = $payload;
        }

        return $grouped;
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (StaffDocumentModel $document): array => $document->toArray(),
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
            facilityColumn: null,
        );
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
