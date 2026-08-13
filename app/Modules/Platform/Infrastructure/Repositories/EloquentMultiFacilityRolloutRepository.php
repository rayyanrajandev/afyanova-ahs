<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Models\MultiFacilityRolloutAcceptanceSignoffModel;
use App\Modules\Platform\Infrastructure\Models\MultiFacilityRolloutCheckpointModel;
use App\Modules\Platform\Infrastructure\Models\MultiFacilityRolloutIncidentModel;
use App\Modules\Platform\Infrastructure\Models\MultiFacilityRolloutPlanModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentMultiFacilityRolloutRepository implements MultiFacilityRolloutRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function searchPlans(
        ?string $query,
        ?string $status,
        ?string $facilityId,
        ?string $goLiveFrom,
        ?string $goLiveTo,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['rollout_code', 'status', 'target_go_live_at', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'target_go_live_at';

        $queryBuilder = MultiFacilityRolloutPlanModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nested) use ($like): void {
                    $nested->where('rollout_code', 'like', $like)
                        ->orWhere('rollback_reason', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($facilityId, fn (Builder $builder, string $value) => $builder->where('facility_id', $value))
            ->when($goLiveFrom, fn (Builder $builder, string $value) => $builder->where('target_go_live_at', '>=', $value))
            ->when($goLiveTo, fn (Builder $builder, string $value) => $builder->where('target_go_live_at', '<=', $value))
            ->orderBy($sortBy, $sortDirection)
            ->orderByDesc('id');

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toPagedResult($paginator);
    }

    public function findPlanById(string $id): ?array
    {
        $query = MultiFacilityRolloutPlanModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $plan = $query->find($id);
        if (! $plan) {
            return null;
        }

        return $this->withPlanDetails($plan->toArray());
    }

    public function findPlanByCodeInTenant(string $tenantId, string $rolloutCode, ?string $excludePlanId = null): ?array
    {
        $query = MultiFacilityRolloutPlanModel::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(rollout_code) = ?', [strtolower(trim($rolloutCode))]);

        if ($excludePlanId !== null) {
            $query->where('id', '!=', $excludePlanId);
        }

        return $query->first()?->toArray();
    }

    public function resolveFacilityInScope(string $facilityId): ?array
    {
        $query = DB::table('facilities')
            ->where('id', $facilityId);

        if ($this->isPlatformScopingEnabled()) {
            $this->platformScopeQueryApplier->apply($query, tenantColumn: 'tenant_id', facilityColumn: 'id');
        }

        $row = $query->first(['id', 'tenant_id', 'code', 'name']);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'tenant_id' => (string) $row->tenant_id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
        ];
    }

    public function createPlan(array $attributes): array
    {
        $plan = new MultiFacilityRolloutPlanModel();
        $plan->fill($attributes);
        $plan->save();

        return $plan->toArray();
    }

    public function updatePlan(string $id, array $attributes): ?array
    {
        $query = MultiFacilityRolloutPlanModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $plan = $query->find($id);
        if (! $plan) {
            return null;
        }

        $plan->fill($attributes);
        $plan->save();

        return $plan->toArray();
    }

    public function listCheckpoints(string $rolloutPlanId): array
    {
        return MultiFacilityRolloutCheckpointModel::query()
            ->where('rollout_plan_id', $rolloutPlanId)
            ->orderBy('checkpoint_name')
            ->get()
            ->map(static fn (MultiFacilityRolloutCheckpointModel $checkpoint): array => $checkpoint->toArray())
            ->all();
    }

    public function upsertCheckpoint(string $rolloutPlanId, string $checkpointCode, array $attributes): array
    {
        $normalizedCode = strtoupper(trim($checkpointCode));

        $checkpoint = MultiFacilityRolloutCheckpointModel::query()
            ->where('rollout_plan_id', $rolloutPlanId)
            ->where('checkpoint_code', $normalizedCode)
            ->first();

        if (! $checkpoint) {
            $checkpoint = new MultiFacilityRolloutCheckpointModel();
            $checkpoint->rollout_plan_id = $rolloutPlanId;
            $checkpoint->checkpoint_code = $normalizedCode;
        }

        $checkpoint->fill(array_merge($attributes, [
            'checkpoint_code' => $normalizedCode,
        ]));
        $checkpoint->save();

        return $checkpoint->toArray();
    }

    public function listIncidents(string $rolloutPlanId): array
    {
        return MultiFacilityRolloutIncidentModel::query()
            ->where('rollout_plan_id', $rolloutPlanId)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (MultiFacilityRolloutIncidentModel $incident): array => $incident->toArray())
            ->all();
    }

    public function findIncidentById(string $rolloutPlanId, string $incidentId): ?array
    {
        $incident = MultiFacilityRolloutIncidentModel::query()
            ->where('rollout_plan_id', $rolloutPlanId)
            ->where('id', $incidentId)
            ->first();

        return $incident?->toArray();
    }

    public function findIncidentByCode(string $rolloutPlanId, string $incidentCode): ?array
    {
        $incident = MultiFacilityRolloutIncidentModel::query()
            ->where('rollout_plan_id', $rolloutPlanId)
            ->where('incident_code', strtoupper(trim($incidentCode)))
            ->first();

        return $incident?->toArray();
    }

    public function createIncident(string $rolloutPlanId, array $attributes): array
    {
        $incident = new MultiFacilityRolloutIncidentModel();
        $incident->fill(array_merge($attributes, [
            'rollout_plan_id' => $rolloutPlanId,
        ]));
        $incident->save();

        return $incident->toArray();
    }

    public function updateIncident(string $rolloutPlanId, string $incidentId, array $attributes): ?array
    {
        $incident = MultiFacilityRolloutIncidentModel::query()
            ->where('rollout_plan_id', $rolloutPlanId)
            ->where('id', $incidentId)
            ->first();

        if (! $incident) {
            return null;
        }

        $incident->fill($attributes);
        $incident->save();

        return $incident->toArray();
    }

    public function upsertAcceptance(string $rolloutPlanId, array $attributes): array
    {
        $acceptance = MultiFacilityRolloutAcceptanceSignoffModel::query()
            ->where('rollout_plan_id', $rolloutPlanId)
            ->first();

        if (! $acceptance) {
            $acceptance = new MultiFacilityRolloutAcceptanceSignoffModel();
            $acceptance->rollout_plan_id = $rolloutPlanId;
        }

        $acceptance->fill($attributes);
        $acceptance->save();

        return $acceptance->toArray();
    }

    public function getAcceptance(string $rolloutPlanId): ?array
    {
        return MultiFacilityRolloutAcceptanceSignoffModel::query()
            ->where('rollout_plan_id', $rolloutPlanId)
            ->first()
            ?->toArray();
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
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function withPlanDetails(array $plan): array
    {
        $rolloutPlanId = (string) ($plan['id'] ?? '');

        $plan['checkpoints'] = $this->listCheckpoints($rolloutPlanId);
        $plan['incidents'] = $this->listIncidents($rolloutPlanId);
        $plan['acceptance'] = $this->getAcceptance($rolloutPlanId);

        return $plan;
    }

    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                fn (MultiFacilityRolloutPlanModel $plan): array => $this->withPlanDetails($plan->toArray()),
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
