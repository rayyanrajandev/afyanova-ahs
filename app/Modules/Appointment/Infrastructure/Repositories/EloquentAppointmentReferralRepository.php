<?php

namespace App\Modules\Appointment\Infrastructure\Repositories;

use App\Modules\Appointment\Domain\Repositories\AppointmentReferralRepositoryInterface;
use App\Modules\Appointment\Infrastructure\Models\AppointmentReferralModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentAppointmentReferralRepository implements AppointmentReferralRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $referral = new AppointmentReferralModel();
        $referral->fill($attributes);
        $referral->save();

        return $referral->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = AppointmentReferralModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $referral = $query->find($id);

        return $referral?->toArray();
    }

    public function findByAppointmentAndId(string $appointmentId, string $id): ?array
    {
        $query = AppointmentReferralModel::query()
            ->where('appointment_id', $appointmentId);
        $this->applyPlatformScopeIfEnabled($query);
        $referral = $query->where('id', $id)->first();

        return $referral?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = AppointmentReferralModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $referral = $query->find($id);
        if (! $referral) {
            return null;
        }

        $referral->fill($attributes);
        $referral->save();

        return $referral->toArray();
    }

    public function existsByReferralNumber(string $referralNumber): bool
    {
        return AppointmentReferralModel::query()
            ->where('referral_number', $referralNumber)
            ->exists();
    }

    public function searchByAppointment(
        string $appointmentId,
        ?string $query,
        ?string $referralType,
        ?string $priority,
        ?string $status,
        ?string $targetFacilityCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['referral_number', 'referral_type', 'priority', 'requested_at', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'requested_at';

        $queryBuilder = AppointmentReferralModel::query()
            ->where('appointment_id', $appointmentId);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applyTextSearch($builder, $searchTerm))
            ->when($referralType, fn (Builder $builder, string $value) => $builder->where('referral_type', $value))
            ->when($priority, fn (Builder $builder, string $value) => $builder->where('priority', $value))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($targetFacilityCode, fn (Builder $builder, string $value) => $builder->where('target_facility_code', strtoupper($value)))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '<=', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCountsByAppointment(
        string $appointmentId,
        ?string $query,
        ?string $referralType,
        ?string $priority,
        ?string $targetFacilityCode,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = AppointmentReferralModel::query()
            ->where('appointment_id', $appointmentId);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applyTextSearch($builder, $searchTerm))
            ->when($referralType, fn (Builder $builder, string $value) => $builder->where('referral_type', $value))
            ->when($priority, fn (Builder $builder, string $value) => $builder->where('priority', $value))
            ->when($targetFacilityCode, fn (Builder $builder, string $value) => $builder->where('target_facility_code', strtoupper($value)))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '<=', $value));

        return $this->statusCountsFromQuery($queryBuilder);
    }

    public function searchNetwork(
        ?string $query,
        ?string $referralType,
        ?string $priority,
        ?string $status,
        ?string $targetFacilityCode,
        ?string $networkMode,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['referral_number', 'referral_type', 'priority', 'requested_at', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'requested_at';

        $queryBuilder = AppointmentReferralModel::query();
        $this->applyNetworkScopeIfEnabled($queryBuilder, $networkMode);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applyTextSearch($builder, $searchTerm))
            ->when($referralType, fn (Builder $builder, string $value) => $builder->where('referral_type', $value))
            ->when($priority, fn (Builder $builder, string $value) => $builder->where('priority', $value))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($targetFacilityCode, fn (Builder $builder, string $value) => $builder->where('target_facility_code', strtoupper($value)))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '<=', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCountsNetwork(
        ?string $query,
        ?string $referralType,
        ?string $priority,
        ?string $targetFacilityCode,
        ?string $networkMode,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = AppointmentReferralModel::query();
        $this->applyNetworkScopeIfEnabled($queryBuilder, $networkMode);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applyTextSearch($builder, $searchTerm))
            ->when($referralType, fn (Builder $builder, string $value) => $builder->where('referral_type', $value))
            ->when($priority, fn (Builder $builder, string $value) => $builder->where('priority', $value))
            ->when($targetFacilityCode, fn (Builder $builder, string $value) => $builder->where('target_facility_code', strtoupper($value)))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('requested_at', '<=', $value));

        return $this->statusCountsFromQuery($queryBuilder);
    }

    private function applyTextSearch(Builder $queryBuilder, string $searchTerm): void
    {
        $like = '%'.$searchTerm.'%';
        $queryBuilder->where(function (Builder $nestedQuery) use ($like): void {
            $nestedQuery
                ->where('referral_number', 'like', $like)
                ->orWhere('target_department', 'like', $like)
                ->orWhere('target_facility_code', 'like', $like)
                ->orWhere('target_facility_name', 'like', $like)
                ->orWhere('referral_reason', 'like', $like)
                ->orWhere('clinical_notes', 'like', $like)
                ->orWhere('handoff_notes', 'like', $like)
                ->orWhere('status_reason', 'like', $like);
        });
    }

    private function statusCountsFromQuery(Builder $queryBuilder): array
    {
        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'requested' => 0,
            'accepted' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'rejected' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'other' && $status !== 'total') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    private function applyNetworkScopeIfEnabled(Builder $query, ?string $networkMode): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $facilityId = $this->platformScopeContext->facilityId();
        if ($facilityId !== null) {
            $mode = in_array($networkMode, ['inbound', 'outbound', 'all'], true)
                ? $networkMode
                : 'all';

            if ($mode === 'inbound') {
                $query->where('target_facility_id', $facilityId);

                return;
            }

            if ($mode === 'outbound') {
                $query->where('facility_id', $facilityId);

                return;
            }

            $query->where(function (Builder $builder) use ($facilityId): void {
                $builder
                    ->where('facility_id', $facilityId)
                    ->orWhere('target_facility_id', $facilityId);
            });

            return;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
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
                static fn (AppointmentReferralModel $referral): array => $referral->toArray(),
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
