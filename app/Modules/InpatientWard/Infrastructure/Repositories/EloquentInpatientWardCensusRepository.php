<?php

namespace App\Modules\InpatientWard\Infrastructure\Repositories;

use App\Modules\Admission\Infrastructure\Models\AdmissionModel;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCensusRepositoryInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInpatientWardCensusRepository implements InpatientWardCensusRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function searchCurrentInpatients(
        ?string $query,
        ?string $ward,
        int $page,
        int $perPage
    ): array {
        $queryBuilder = AdmissionModel::query()
            ->with(['patient:id,patient_number,first_name,middle_name,last_name'])
            ->whereIn('status', ['admitted', 'transferred']);

        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $nameTokens = array_values(array_filter(preg_split('/\s+/', trim($searchTerm)) ?: []));

                $builder->where(function (Builder $nestedQuery) use ($like, $nameTokens): void {
                    $nestedQuery
                        ->where('admission_number', 'like', $like)
                        ->orWhere('patient_id', 'like', $like)
                        ->orWhere('ward', 'like', $like)
                        ->orWhere('bed', 'like', $like)
                        ->orWhere('admission_reason', 'like', $like)
                        ->orWhereHas('patient', function (Builder $patientQuery) use ($like, $nameTokens): void {
                            $patientQuery
                                ->where('patient_number', 'like', $like)
                                ->orWhere('first_name', 'like', $like)
                                ->orWhere('middle_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like);

                            if (count($nameTokens) > 1) {
                                $patientQuery->orWhere(function (Builder $tokenQuery) use ($nameTokens): void {
                                    foreach ($nameTokens as $token) {
                                        $tokenLike = '%'.$token.'%';
                                        $tokenQuery->where(function (Builder $namePartQuery) use ($tokenLike): void {
                                            $namePartQuery
                                                ->where('first_name', 'like', $tokenLike)
                                                ->orWhere('middle_name', 'like', $tokenLike)
                                                ->orWhere('last_name', 'like', $tokenLike);
                                        });
                                    }
                                });
                            }
                        });
                });
            })
            ->when($ward, fn (Builder $builder, string $requestedWard) => $builder->where('ward', $requestedWard))
            ->orderByDesc('admitted_at');

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function findCurrentAdmissionById(string $admissionId): ?array
    {
        $query = AdmissionModel::query()
            ->with(['patient:id,patient_number,first_name,middle_name,last_name'])
            ->whereIn('status', ['admitted', 'transferred']);
        $this->applyPlatformScopeIfEnabled($query);

        $admission = $query->find($admissionId);

        return $admission?->toArray();
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
                static fn (AdmissionModel $admission): array => $admission->toArray(),
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