<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ListCashierQueueUseCase
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly DefaultCurrencyResolverInterface $defaultCurrencyResolver,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters): array
    {
        $status = $this->normalizeNullableString($filters['status'] ?? null);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = max(min((int) ($filters['perPage'] ?? 20), 100), 1);
        $query = $this->normalizeNullableString($filters['q'] ?? null);

        // Candidate patients are resolved entirely at the SQL level below
        // (correlated EXISTS subqueries + a real ->paginate() call) instead
        // of pulling every matching patient ID into PHP first and slicing —
        // that in-memory approach loaded the whole matching population on
        // every request regardless of page size.
        $patientsQuery = PatientModel::query();

        if ($this->isPlatformScopingEnabled()) {
            // facilityColumn: null — patients carry tenant_id but no
            // facility_id column (they're tenant-wide, not facility-scoped),
            // matching EloquentPatientRepository's own scoping call.
            $this->platformScopeQueryApplier->apply(
                $patientsQuery,
                tenantColumn: 'tenant_id',
                facilityColumn: null,
            );
        }

        $this->applyStatusFilter($patientsQuery, $status);

        if ($query !== null) {
            $patientsQuery->where(function (EloquentBuilder $w) use ($query) {
                $w->where('first_name', 'ilike', "%{$query}%")
                    ->orWhere('last_name', 'ilike', "%{$query}%")
                    ->orWhere('patient_number', 'ilike', "%{$query}%")
                    ->orWhere('phone', 'ilike', "%{$query}%");
            });
        }

        // The original PHP-side slice had no stable order either, but a real
        // SQL OFFSET/LIMIT needs one explicitly or rows can shift between
        // pages as concurrent writes happen — order by id for a cheap,
        // fully deterministic tiebreaker.
        $paginator = $patientsQuery->orderBy('id')->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        $entries = $this->buildQueueEntries($paginator->getCollection(), $status);

        return [
            'data' => $entries->toArray(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                // Laravel's paginator floors lastPage at 1 even with zero
                // results; keep the previous "0 when nothing matches" shape
                // so existing consumers see no behavior change.
                'lastPage' => $paginator->total() > 0 ? $paginator->lastPage() : 0,
            ],
        ];
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    /**
     * Restricts $query to patients matching the requested cashier-queue
     * status, entirely via correlated EXISTS subqueries against `patients` —
     * the SQL-level equivalent of the four candidate-ID scans this use case
     * used to run into PHP arrays before merging/deduplicating them there.
     */
    private function applyStatusFilter(EloquentBuilder $query, ?string $status): void
    {
        if ($status === 'unpaid') {
            $query->whereExists(fn (QueryBuilder $q) => $this->unpaidInvoiceExists($q));

            return;
        }

        if ($status === 'paid') {
            // Matches the original findPatientsWithAllInvoicesPaid(): at
            // least one non-cancelled/voided invoice, and none of them have
            // an outstanding balance.
            $query->whereExists(fn (QueryBuilder $q) => $this->anyActiveInvoiceExists($q))
                ->whereNotExists(fn (QueryBuilder $q) => $this->unpaidInvoiceExists($q));

            return;
        }

        // 'all' (or unrecognized status): any patient with an active
        // invoice, an unbilled completed service, or an active consultation —
        // the same three-source union the original code merged in PHP.
        $query->where(function (EloquentBuilder $outer) {
            $outer->orWhereExists(fn (QueryBuilder $q) => $this->anyActiveInvoiceExists($q));
            $outer->orWhereExists(fn (QueryBuilder $q) => $this->labUnbilledExists($q));
            $outer->orWhereExists(fn (QueryBuilder $q) => $this->pharmacyUnbilledExists($q));
            $outer->orWhereExists(fn (QueryBuilder $q) => $this->radiologyUnbilledExists($q));
            $outer->orWhereExists(fn (QueryBuilder $q) => $this->clinicalProcedureUnbilledExists($q));
            $outer->orWhereExists(fn (QueryBuilder $q) => $this->theatreUnbilledExists($q));
        });
    }

    private function unpaidInvoiceExists(QueryBuilder $query): void
    {
        $query->select(\DB::raw(1))
            ->from('billing_invoices')
            ->whereColumn('billing_invoices.patient_id', 'patients.id')
            ->whereNotIn('billing_invoices.status', ['cancelled', 'voided'])
            ->where('billing_invoices.balance_amount', '>', 0);
    }

    /**
     * Any invoice that isn't cancelled/voided, regardless of balance —
     * matches the original findPatientsWithUnpaidInvoices(null) call used
     * only when merging the 'all' bucket (a slight misnomer: with a null
     * $status, that method applied no balance filter at all).
     */
    private function anyActiveInvoiceExists(QueryBuilder $query): void
    {
        $query->select(\DB::raw(1))
            ->from('billing_invoices')
            ->whereColumn('billing_invoices.patient_id', 'patients.id')
            ->whereNotIn('billing_invoices.status', ['cancelled', 'voided']);
    }

    private function labUnbilledExists(QueryBuilder $query): void
    {
        $query->select(\DB::raw(1))
            ->from('laboratory_orders')
            ->whereColumn('laboratory_orders.patient_id', 'patients.id')
            ->where(function (QueryBuilder $q) {
                $q->where('status', 'completed')->orWhereNotNull('resulted_at');
            })
            ->whereNull('entered_in_error_at');
    }

    private function pharmacyUnbilledExists(QueryBuilder $query): void
    {
        $query->select(\DB::raw(1))
            ->from('pharmacy_orders')
            ->whereColumn('pharmacy_orders.patient_id', 'patients.id')
            ->where(function (QueryBuilder $q) {
                $q->where('status', 'dispensed')
                    ->orWhere('status', 'partially_dispensed')
                    ->orWhere('quantity_dispensed', '>', 0);
            })
            ->whereNull('entered_in_error_at');
    }

    private function radiologyUnbilledExists(QueryBuilder $query): void
    {
        $query->select(\DB::raw(1))
            ->from('radiology_orders')
            ->whereColumn('radiology_orders.patient_id', 'patients.id')
            ->where(function (QueryBuilder $q) {
                $q->where('status', 'completed')->orWhereNotNull('completed_at');
            })
            ->whereNull('entered_in_error_at');
    }

    private function clinicalProcedureUnbilledExists(QueryBuilder $query): void
    {
        $query->select(\DB::raw(1))
            ->from('clinical_procedure_orders')
            ->whereColumn('clinical_procedure_orders.patient_id', 'patients.id')
            ->where(function (QueryBuilder $q) {
                $q->where('status', 'completed')->orWhereNotNull('completed_at');
            })
            ->whereNull('entered_in_error_at');
    }

    private function theatreUnbilledExists(QueryBuilder $query): void
    {
        $query->select(\DB::raw(1))
            ->from('theatre_procedures')
            ->whereColumn('theatre_procedures.patient_id', 'patients.id')
            ->where(function (QueryBuilder $q) {
                $q->where('status', 'completed')->orWhereNotNull('completed_at');
            });
    }

    /**
     * @param  Collection<int, PatientModel>  $patients  Already the current
     *   page only (typically ~20 rows) — every sub-query below is bounded to
     *   this page's patient IDs, not the full matching population.
     * @return Collection<int, array<string, mixed>>
     */
    private function buildQueueEntries(Collection $patients, ?string $status): Collection
    {
        $patientIds = $patients->pluck('id')->toArray();

        if ($patientIds === []) {
            return collect();
        }

        // Batch fetch unpaid invoices per patient
        $invoicesByPatient = BillingInvoiceModel::query()
            ->whereIn('patient_id', $patientIds)
            ->whereNotIn('status', ['cancelled', 'voided'])
            ->get()
            ->groupBy('patient_id');

        // Batch fetch unbilled service counts per patient
        $unbilledCounts = $this->countUnbilledServices($patientIds);

        return $patients->map(function (PatientModel $patient) use ($invoicesByPatient, $unbilledCounts, $status) {
            $invoices = $invoicesByPatient->get($patient->id, collect());
            $unpaidInvoices = $invoices->filter(fn ($inv) => $status !== 'paid' && (float) ($inv->balance_amount ?? 0) > 0);
            $paidInvoices = $invoices->filter(fn ($inv) => $status !== 'unpaid' && (float) ($inv->balance_amount ?? 0) <= 0);

            $totalUnpaid = $unpaidInvoices->sum('balance_amount');
            $totalPaid = $paidInvoices->sum('paid_amount');
            $unbilledCount = $unbilledCounts[$patient->id] ?? 0;

            return [
                'patientId' => $patient->id,
                'patientNumber' => $patient->patient_number,
                'patientName' => trim($patient->first_name . ' ' . $patient->last_name),
                'phone' => $patient->phone,
                'unpaidInvoiceCount' => $unpaidInvoices->count(),
                'totalUnpaidAmount' => (float) $totalUnpaid,
                'paidInvoiceCount' => $paidInvoices->count(),
                'totalPaidAmount' => (float) $totalPaid,
                'unbilledServiceCount' => $unbilledCount,
                'summaryLabel' => $this->buildSummaryLabel($unpaidInvoices->count(), $totalUnpaid, $unbilledCount),
            ];
        });
    }

    /**
     * @param  string[]  $patientIds
     * @return array<string, int>
     */
    private function countUnbilledServices(array $patientIds): array
    {
        if (empty($patientIds)) {
            return [];
        }

        $labCount = LaboratoryOrderModel::query()
            ->select('patient_id', \DB::raw('COUNT(*) as cnt'))
            ->whereIn('patient_id', $patientIds)
            ->where(function ($q) {
                $q->where('status', 'completed')
                    ->orWhereNotNull('resulted_at');
            })
            ->whereNull('entered_in_error_at')
            ->groupBy('patient_id')
            ->pluck('cnt', 'patient_id')
            ->toArray();

        $pharmacyCount = PharmacyOrderModel::query()
            ->select('patient_id', \DB::raw('COUNT(*) as cnt'))
            ->whereIn('patient_id', $patientIds)
            ->where(function ($q) {
                $q->where('status', 'dispensed')
                    ->orWhere('status', 'partially_dispensed')
                    ->orWhere('quantity_dispensed', '>', 0);
            })
            ->whereNull('entered_in_error_at')
            ->groupBy('patient_id')
            ->pluck('cnt', 'patient_id')
            ->toArray();

        $radiologyCount = RadiologyOrderModel::query()
            ->select('patient_id', \DB::raw('COUNT(*) as cnt'))
            ->whereIn('patient_id', $patientIds)
            ->where(function ($q) {
                $q->where('status', 'completed')
                    ->orWhereNotNull('completed_at');
            })
            ->whereNull('entered_in_error_at')
            ->groupBy('patient_id')
            ->pluck('cnt', 'patient_id')
            ->toArray();

        $clinicalProcedureCount = ClinicalProcedureOrderModel::query()
            ->select('patient_id', \DB::raw('COUNT(*) as cnt'))
            ->whereIn('patient_id', $patientIds)
            ->where(function ($q) {
                $q->where('status', 'completed')
                    ->orWhereNotNull('completed_at');
            })
            ->whereNull('entered_in_error_at')
            ->groupBy('patient_id')
            ->pluck('cnt', 'patient_id')
            ->toArray();

        $theatreCount = TheatreProcedureModel::query()
            ->select('patient_id', \DB::raw('COUNT(*) as cnt'))
            ->whereIn('patient_id', $patientIds)
            ->where(function ($q) {
                $q->where('status', 'completed')
                    ->orWhereNotNull('completed_at');
            })
            ->groupBy('patient_id')
            ->pluck('cnt', 'patient_id')
            ->toArray();

        $counts = [];
        foreach ($patientIds as $pid) {
            $counts[$pid] = ($labCount[$pid] ?? 0)
                + ($pharmacyCount[$pid] ?? 0)
                + ($radiologyCount[$pid] ?? 0)
                + ($clinicalProcedureCount[$pid] ?? 0)
                + ($theatreCount[$pid] ?? 0);
        }

        return $counts;
    }

    private function buildSummaryLabel(int $unpaidCount, float $totalUnpaid, int $unbilledCount): string
    {
        $parts = [];

        if ($unpaidCount > 0) {
            $parts[] = "{$unpaidCount} unpaid invoice" . ($unpaidCount === 1 ? '' : 's') . ' (' . number_format($totalUnpaid, 0) . ')';
        }

        if ($unbilledCount > 0) {
            $parts[] = "{$unbilledCount} unbilled service" . ($unbilledCount === 1 ? '' : 's');
        }

        return implode(' · ', $parts) ?: 'No pending charges';
    }

    private function normalizeBoolean($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function normalizeNullableString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
