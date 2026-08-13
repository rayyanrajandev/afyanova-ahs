<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDispensingClaimLinkRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDispensingClaimLinkModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventoryDispensingClaimLinkRepository implements InventoryDispensingClaimLinkRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $link = new InventoryDispensingClaimLinkModel();
        $link->fill($attributes);
        $link->save();

        return $link->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventoryDispensingClaimLinkModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $link = $query->find($id);

        return $link?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventoryDispensingClaimLinkModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $link = $query->find($id);
        if (! $link) {
            return null;
        }

        $link->fill($attributes);
        $link->save();

        return $link->toArray();
    }

    public function search(
        ?string $patientId,
        ?string $claimStatus,
        ?string $insuranceClaimId,
        ?string $query,
        int $page,
        int $perPage
    ): array {
        $builder = InventoryDispensingClaimLinkModel::query();
        $this->applyPlatformScopeIfEnabled($builder);

        if ($patientId !== null && trim($patientId) !== '') {
            $builder->where('patient_id', $patientId);
        }

        if ($claimStatus !== null && trim($claimStatus) !== '') {
            $builder->where('claim_status', $claimStatus);
        }

        if ($insuranceClaimId !== null && trim($insuranceClaimId) !== '') {
            $builder->where('insurance_claim_id', $insuranceClaimId);
        }

        if ($query !== null && trim($query) !== '') {
            $term = '%' . trim($query) . '%';
            $builder->where(function (Builder $q) use ($term) {
                $q->where('nhif_code', 'LIKE', $term)
                    ->orWhere('payer_name', 'LIKE', $term)
                    ->orWhere('payer_reference', 'LIKE', $term)
                    ->orWhere('notes', 'LIKE', $term);
            });
        }

        $builder->orderByDesc('created_at');

        return $this->toSearchResult($builder->paginate(perPage: $perPage, page: $page));
    }

    public function listByInsuranceClaim(string $insuranceClaimId): array
    {
        $builder = InventoryDispensingClaimLinkModel::query();
        $this->applyPlatformScopeIfEnabled($builder);

        return $builder
            ->where('insurance_claim_id', $insuranceClaimId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($m) => $m->toArray())
            ->all();
    }

    public function listByPatient(string $patientId, int $page, int $perPage): array
    {
        $builder = InventoryDispensingClaimLinkModel::query();
        $this->applyPlatformScopeIfEnabled($builder);

        $builder->where('patient_id', $patientId)->orderByDesc('created_at');

        return $this->toSearchResult($builder->paginate(perPage: $perPage, page: $page));
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
        // 'inventory_procurement_platform_scoping' isn't a registered flag
        // key (the real ones are 'platform.multi_facility_scoping'/
        // 'platform.multi_tenant_isolation') — this silently returned false
        // regardless of the real flags' state.
        try {
            return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
                || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
        } catch (\Throwable) {
            return false;
        }
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->map(fn ($m) => $m->toArray())->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
