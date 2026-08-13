<?php

namespace App\Modules\ClinicalProcedure\Infrastructure\Repositories;

use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderRepositoryInterface;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Support\ClinicalOrders\ClinicalOrderEntryState;
use App\Support\ClinicalOrders\ClinicalOrderPatientTextSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentClinicalProcedureOrderRepository implements ClinicalProcedureOrderRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $order = new ClinicalProcedureOrderModel();
        $order->fill($attributes);
        $order->save();

        return $order->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = ClinicalProcedureOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $order = $query->find($id);

        return $order?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = ClinicalProcedureOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $order = $query->find($id);
        if (! $order) {
            return null;
        }

        $order->fill($attributes);
        $order->save();

        return $order->toArray();
    }

    public function delete(string $id): bool
    {
        $query = ClinicalProcedureOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $order = $query->find($id);
        if (! $order) {
            return false;
        }

        return (bool) $order->delete();
    }

    public function existsByOrderNumber(string $orderNumber): bool
    {
        return ClinicalProcedureOrderModel::query()
            ->where('order_number', $orderNumber)
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $encounterId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $status,
        ?array $statuses,
        ?string $procedureSetting,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['order_number', 'ordered_at', 'scheduled_for', 'status', 'procedure_setting', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'ordered_at';

        $queryBuilder = ClinicalProcedureOrderModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyActiveEntryStateScope($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applyTextSearch($builder, $searchTerm))
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($encounterId, fn (Builder $builder, string $requestedEncounterId) => $builder->where('encounter_id', $requestedEncounterId))
            ->when($appointmentId, fn (Builder $builder, string $requestedAppointmentId) => $builder->where('appointment_id', $requestedAppointmentId))
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when(
                $status === null && is_array($statuses) && $statuses !== [],
                fn (Builder $builder) => $builder->whereIn('status', $statuses),
            )
            ->when($procedureSetting, fn (Builder $builder, string $requestedSetting) => $builder->where('procedure_setting', $requestedSetting))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('ordered_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('ordered_at', '<=', $endDateTime))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $procedureSetting,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = ClinicalProcedureOrderModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyActiveEntryStateScope($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applyTextSearch($builder, $searchTerm))
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($appointmentId, fn (Builder $builder, string $requestedAppointmentId) => $builder->where('appointment_id', $requestedAppointmentId))
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId))
            ->when($procedureSetting, fn (Builder $builder, string $requestedSetting) => $builder->where('procedure_setting', $requestedSetting))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('ordered_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('ordered_at', '<=', $toDateTime));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'ordered' => 0,
            'scheduled' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0,
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

    private function applyTextSearch(Builder $queryBuilder, string $searchTerm): void
    {
        ClinicalOrderPatientTextSearch::apply(
            $queryBuilder,
            $searchTerm,
            $this->platformScopeQueryApplier,
            $this->isPlatformScopingEnabled(),
            static function (Builder $nestedQuery, string $like): void {
                $nestedQuery
                    ->whereRaw('LOWER(order_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(procedure_code, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(procedure_setting, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(procedure_description, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(clinical_indication, \'\')) LIKE ?', [$like]);
            },
        );
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

    private function applyActiveEntryStateScope(Builder $query): void
    {
        $query->where('entry_state', ClinicalOrderEntryState::ACTIVE->value);
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (ClinicalProcedureOrderModel $order): array => $order->toArray(),
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
