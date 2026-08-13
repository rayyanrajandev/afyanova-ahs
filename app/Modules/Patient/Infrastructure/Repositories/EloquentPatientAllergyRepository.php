<?php

namespace App\Modules\Patient\Infrastructure\Repositories;

use App\Modules\Patient\Domain\Repositories\PatientAllergyRepositoryInterface;
use App\Modules\Patient\Infrastructure\Models\PatientAllergyModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentPatientAllergyRepository implements PatientAllergyRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $record = new PatientAllergyModel();
        $record->fill($attributes);
        $record->save();

        return $record->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = PatientAllergyModel::query();
        $this->applyTenantScopeIfEnabled($query);

        return $query->find($id)?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = PatientAllergyModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $record = $query->find($id);
        if (! $record) {
            return null;
        }

        $record->fill($attributes);
        $record->save();

        return $record->toArray();
    }

    public function listByPatientId(
        string $patientId,
        ?string $status,
        int $page,
        int $perPage
    ): array {
        $query = PatientAllergyModel::query();
        $this->applyTenantScopeIfEnabled($query);

        $paginator = $query
            ->where('patient_id', $patientId)
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', ['active'])
            ->orderByDesc('noted_at')
            ->orderByDesc('updated_at')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator);
    }

    public function listActiveByPatientId(string $patientId): array
    {
        $query = PatientAllergyModel::query();
        $this->applyTenantScopeIfEnabled($query);

        return $query
            ->where('patient_id', $patientId)
            ->where('status', 'active')
            ->orderByDesc('severity')
            ->orderBy('substance_name')
            ->get()
            ->map(static fn (PatientAllergyModel $record): array => $record->toArray())
            ->all();
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

    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (PatientAllergyModel $record): array => $record->toArray(),
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
