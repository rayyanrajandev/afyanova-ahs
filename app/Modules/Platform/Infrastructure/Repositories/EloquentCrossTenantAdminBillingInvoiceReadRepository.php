<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminBillingInvoiceReadRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentCrossTenantAdminBillingInvoiceReadRepository implements CrossTenantAdminBillingInvoiceReadRepositoryInterface
{
    public function searchByTenantId(
        string $tenantId,
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?string $currencyCode,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['invoice_number', 'invoice_date', 'total_amount', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'invoice_date';

        $queryBuilder = BillingInvoiceModel::query()
            ->where('tenant_id', $tenantId)
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('invoice_number', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                });
            })
            ->when($patientId, fn (Builder $builder, string $requestedPatientId) => $builder->where('patient_id', $requestedPatientId))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($currencyCode, fn (Builder $builder, string $requestedCurrencyCode) => $builder->where('currency_code', $requestedCurrencyCode))
            ->when($fromDateTime, fn (Builder $builder, string $startDateTime) => $builder->where('invoice_date', '>=', $startDateTime))
            ->when($toDateTime, fn (Builder $builder, string $endDateTime) => $builder->where('invoice_date', '<=', $endDateTime))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (BillingInvoiceModel $invoice): array => $invoice->toArray(),
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
