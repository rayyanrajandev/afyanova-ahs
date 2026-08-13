<?php

namespace App\Modules\ClaimsInsurance\Infrastructure\Repositories;

use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseRepositoryInterface;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentClaimsInsuranceCaseRepository implements ClaimsInsuranceCaseRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $case = new ClaimsInsuranceCaseModel();
        $case->fill($attributes);
        $case->save();

        return $case->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = ClaimsInsuranceCaseModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $case = $query->find($id);

        return $case?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = ClaimsInsuranceCaseModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $case = $query->find($id);
        if (! $case) {
            return null;
        }

        $case->fill($attributes);
        $case->save();

        return $case->toArray();
    }

    public function existsByClaimNumber(string $claimNumber): bool
    {
        return ClaimsInsuranceCaseModel::query()
            ->where('claim_number', $claimNumber)
            ->exists();
    }

    public function existsActiveForInvoice(string $invoiceId, ?string $excludeId = null): bool
    {
        $query = ClaimsInsuranceCaseModel::query()
            ->where('invoice_id', $invoiceId)
            ->whereNotIn('status', ['cancelled']);

        $this->applyPlatformScopeIfEnabled($query);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function search(
        ?string $query,
        ?string $invoiceId,
        ?string $patientId,
        ?string $status,
        ?string $reconciliationStatus,
        ?string $reconciliationExceptionStatus,
        ?string $payerType,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, [
            'claim_number',
            'submitted_at',
            'adjudicated_at',
            'settled_at',
            'status',
            'reconciliation_status',
            'reconciliation_exception_status',
            'reconciliation_follow_up_due_at',
            'payer_type',
            'claim_amount',
            'approved_amount',
            'settled_amount',
            'created_at',
            'updated_at',
        ], true)
            ? $sortBy
            : 'created_at';

        $queryBuilder = ClaimsInsuranceCaseModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('claim_number', 'like', $like)
                        ->orWhere('payer_name', 'like', $like)
                        ->orWhere('payer_reference', 'like', $like)
                        ->orWhere('decision_reason', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($invoiceId, fn (Builder $builder, string $requestedInvoiceId) => $builder->where('invoice_id', $requestedInvoiceId))
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($reconciliationStatus, fn (Builder $builder, string $requestedReconciliationStatus) => $builder->where('reconciliation_status', $requestedReconciliationStatus))
            ->when($reconciliationExceptionStatus, fn (Builder $builder, string $requestedExceptionStatus) => $builder->where('reconciliation_exception_status', $requestedExceptionStatus))
            ->when($payerType, fn (Builder $builder, string $requestedPayerType) => $builder->where('payer_type', $requestedPayerType))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('submitted_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('submitted_at', '<=', $endDateTime))
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
        ?string $invoiceId,
        ?string $patientId,
        ?string $reconciliationStatus,
        ?string $reconciliationExceptionStatus,
        ?string $payerType,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $queryBuilder = ClaimsInsuranceCaseModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('claim_number', 'like', $like)
                        ->orWhere('payer_name', 'like', $like)
                        ->orWhere('payer_reference', 'like', $like)
                        ->orWhere('decision_reason', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($invoiceId, fn (Builder $builder, string $requestedInvoiceId) => $builder->where('invoice_id', $requestedInvoiceId))
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($reconciliationStatus, fn (Builder $builder, string $requestedReconciliationStatus) => $builder->where('reconciliation_status', $requestedReconciliationStatus))
            ->when($reconciliationExceptionStatus, fn (Builder $builder, string $requestedExceptionStatus) => $builder->where('reconciliation_exception_status', $requestedExceptionStatus))
            ->when($payerType, fn (Builder $builder, string $requestedPayerType) => $builder->where('payer_type', $requestedPayerType))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('submitted_at', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('submitted_at', '<=', $endDateTime));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'draft' => 0,
            'submitted' => 0,
            'adjudicating' => 0,
            'approved' => 0,
            'rejected' => 0,
            'partial' => 0,
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
                static fn (ClaimsInsuranceCaseModel $case): array => $case->toArray(),
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
