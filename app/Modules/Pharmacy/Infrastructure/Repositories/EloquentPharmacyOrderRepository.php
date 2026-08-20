<?php

namespace App\Modules\Pharmacy\Infrastructure\Repositories;

use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Support\ClinicalOrders\ClinicalOrderEntryState;
use App\Support\ClinicalOrders\ClinicalOrderPatientTextSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;

class EloquentPharmacyOrderRepository implements PharmacyOrderRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $order = new PharmacyOrderModel();
        $order->fill($attributes);
        $order->save();

        return $order->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $order = $query->find($id);

        return $order?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = PharmacyOrderModel::query();
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
        $query = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $order = $query->find($id);
        if (! $order) {
            return false;
        }

        return (bool) $order->delete();
    }

    public function existsByOrderNumber(string $orderNumber): bool
    {
        return PharmacyOrderModel::query()
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
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        bool $authorizedOnly = false,
    ): array {
        $sortBy = in_array($sortBy, ['order_number', 'ordered_at', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'ordered_at';

        $queryBuilder = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyActiveEntryStateScope($queryBuilder);

        if ($authorizedOnly) {
            $this->applyAuthorizedFilter($queryBuilder);
        }

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
        ?string $fromDateTime,
        ?string $toDateTime,
        bool $authorizedOnly = false,
    ): array {
        $queryBuilder = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);
        $this->applyActiveEntryStateScope($queryBuilder);

        if ($authorizedOnly) {
            $this->applyAuthorizedFilter($queryBuilder);
        }

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applyTextSearch($builder, $searchTerm))
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($appointmentId, fn (Builder $builder, string $requestedAppointmentId) => $builder->where('appointment_id', $requestedAppointmentId))
            ->when($admissionId, fn (Builder $builder, string $requestedAdmissionId) => $builder->where('admission_id', $requestedAdmissionId))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('ordered_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('ordered_at', '<=', $endDateTime));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'pending' => 0,
            'in_preparation' => 0,
            'partially_dispensed' => 0,
            'dispensed' => 0,
            'cancelled' => 0,
            'reconciliation_pending' => 0,
            'reconciliation_completed' => 0,
            'reconciliation_exception' => 0,
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

        $counts['reconciliation_pending'] = (clone $queryBuilder)
            ->where('status', 'dispensed')
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('reconciliation_status')
                    ->orWhere('reconciliation_status', 'pending');
            })
            ->count();
        $counts['reconciliation_completed'] = (clone $queryBuilder)
            ->where('status', 'dispensed')
            ->where('reconciliation_status', 'completed')
            ->count();
        $counts['reconciliation_exception'] = (clone $queryBuilder)
            ->where('status', 'dispensed')
            ->where('reconciliation_status', 'exception')
            ->count();

        return $counts;
    }

    public function recentActiveMedicationHistory(
        string $patientId,
        string $excludeOrderId,
        int $limit = 5
    ): array {
        $query = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $this->applyActiveEntryStateScope($query);
        $this->applyNotEnteredInErrorScope($query);

        return $query
            ->where('patient_id', $patientId)
            ->where('id', '!=', $excludeOrderId)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('ordered_at')
            ->limit(max($limit, 1))
            ->get()
            ->map(static fn (PharmacyOrderModel $order): array => $order->toArray())
            ->all();
    }

    public function unreconciledReleasedOrders(
        string $patientId,
        string $excludeOrderId,
        int $limit = 5
    ): array {
        $query = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $this->applyActiveEntryStateScope($query);
        $this->applyNotEnteredInErrorScope($query);

        return $query
            ->where('patient_id', $patientId)
            ->where('id', '!=', $excludeOrderId)
            ->where('status', 'dispensed')
            ->whereNotNull('verified_at')
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('reconciliation_status')
                    ->orWhere('reconciliation_status', 'pending')
                    ->orWhere('reconciliation_status', 'exception');
            })
            ->orderByDesc('ordered_at')
            ->limit(max($limit, 1))
            ->get()
            ->map(static fn (PharmacyOrderModel $order): array => $order->toArray())
            ->all();
    }

    public function unreconciledReleasedOrdersForPatient(
        string $patientId,
        int $limit = 5
    ): array {
        $query = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $this->applyActiveEntryStateScope($query);
        $this->applyNotEnteredInErrorScope($query);

        return $query
            ->where('patient_id', $patientId)
            ->where('status', 'dispensed')
            ->whereNotNull('verified_at')
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('reconciliation_status')
                    ->orWhere('reconciliation_status', 'pending')
                    ->orWhere('reconciliation_status', 'exception');
            })
            ->orderByDesc('ordered_at')
            ->limit(max($limit, 1))
            ->get()
            ->map(static fn (PharmacyOrderModel $order): array => $order->toArray())
            ->all();
    }

    public function matchingActiveMedicationOrders(
        string $patientId,
        ?string $medicationCode,
        ?string $medicationName,
        ?string $excludeOrderId = null,
        int $limit = 10
    ): array {
        $normalizedCode = trim((string) $medicationCode);
        $normalizedName = mb_strtolower(trim((string) $medicationName));

        if ($normalizedCode === '' && $normalizedName === '') {
            return [];
        }

        $query = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $this->applyActiveEntryStateScope($query);
        $this->applyNotEnteredInErrorScope($query);

        return $query
            ->where('patient_id', $patientId)
            ->when(
                filled($excludeOrderId),
                fn (Builder $builder) => $builder->where('id', '!=', $excludeOrderId),
            )
            ->whereIn('status', ['pending', 'in_preparation', 'partially_dispensed', 'dispensed'])
            ->where(function (Builder $builder) use ($normalizedCode, $normalizedName): void {
                if ($normalizedCode !== '') {
                    $builder->orWhere('medication_code', $normalizedCode);
                }

                if ($normalizedName !== '') {
                    $builder->orWhereRaw('LOWER(medication_name) = ?', [$normalizedName]);
                }
            })
            ->orderByDesc('ordered_at')
            ->limit(max($limit, 1))
            ->get()
            ->map(static fn (PharmacyOrderModel $order): array => $order->toArray())
            ->all();
    }

    public function activeMedicationOrdersForPatient(
        string $patientId,
        ?string $excludeOrderId = null,
        int $limit = 25
    ): array {
        $query = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $this->applyActiveEntryStateScope($query);
        $this->applyNotEnteredInErrorScope($query);

        return $query
            ->where('patient_id', $patientId)
            ->when(
                filled($excludeOrderId),
                fn (Builder $builder) => $builder->where('id', '!=', $excludeOrderId),
            )
            ->whereIn('status', ['pending', 'in_preparation', 'partially_dispensed', 'dispensed'])
            ->orderByDesc('ordered_at')
            ->limit(max($limit, 1))
            ->get()
            ->map(static fn (PharmacyOrderModel $order): array => $order->toArray())
            ->all();
    }

    public function activeDispensedOrders(string $patientId, int $limit = 25): array
    {
        $query = PharmacyOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $this->applyActiveEntryStateScope($query);
        $this->applyNotEnteredInErrorScope($query);

        return $query
            ->where('patient_id', $patientId)
            ->where('status', 'dispensed')
            ->orderByDesc('dispensed_at')
            ->orderByDesc('ordered_at')
            ->limit(max($limit, 1))
            ->get()
            ->map(static fn (PharmacyOrderModel $order): array => $order->toArray())
            ->all();
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
                    ->orWhereRaw('LOWER(COALESCE(medication_code, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(medication_name, \'\')) LIKE ?', [$like]);
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

    private function applyAuthorizedFilter(Builder $builder): void
    {
        if (! ChargeSourceKind::PHARMACY_ORDER->prepaidGateEnabled()) {
            return;
        }

        $builder->where(function (Builder $q): void {
            $q->whereIn('status', [
                PharmacyOrderStatus::IN_PREPARATION->value,
                PharmacyOrderStatus::PARTIALLY_DISPENSED->value,
                PharmacyOrderStatus::DISPENSED->value,
            ])->orWhere(function (Builder $subQ): void {
                $subQ->whereRaw("id::text IN (SELECT source_workflow_id FROM service_charges WHERE source_workflow_kind = ? AND status IN (?, ?))", [
                    ChargeSourceKind::PHARMACY_ORDER->value,
                    ServiceChargeStatus::AUTHORIZED->value,
                    ServiceChargeStatus::FULFILLED->value,
                ]);
            });
        });
    }

    private function applyNotEnteredInErrorScope(Builder $query): void
    {
        $query
            ->whereNull('entered_in_error_at')
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('lifecycle_reason_code')
                    ->orWhere('lifecycle_reason_code', '!=', 'entered_in_error');
            });
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (PharmacyOrderModel $order): array => $order->toArray(),
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
