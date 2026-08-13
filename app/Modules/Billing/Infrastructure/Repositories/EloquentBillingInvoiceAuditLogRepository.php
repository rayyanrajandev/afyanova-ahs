<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceAuditLogRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceAuditLogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentBillingInvoiceAuditLogRepository implements BillingInvoiceAuditLogRepositoryInterface
{
    public function write(
        string $billingInvoiceId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void {
        BillingInvoiceAuditLogModel::query()->create([
            'billing_invoice_id' => $billingInvoiceId,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listByBillingInvoiceId(
        string $billingInvoiceId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array
    {
        $paginator = BillingInvoiceAuditLogModel::query()
            ->where('billing_invoice_id', $billingInvoiceId)
            ->when($query, fn (Builder $builder, string $value) => $builder->whereRaw('LOWER(action) LIKE ?', ['%'.strtolower($value).'%']))
            ->when($action, fn (Builder $builder, string $value) => $builder->where('action', $value))
            ->when($actorType === 'system', fn (Builder $builder) => $builder->whereNull('actor_id'))
            ->when($actorType === 'user', fn (Builder $builder) => $builder->whereNotNull('actor_id'))
            ->when($actorId !== null, fn (Builder $builder) => $builder->where('actor_id', $actorId))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '<=', $value))
            ->orderByDesc('created_at')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator);
    }

    public function listByBillingInvoiceIds(array $billingInvoiceIds): array
    {
        if ($billingInvoiceIds === []) {
            return [];
        }

        return BillingInvoiceAuditLogModel::query()
            ->whereIn('billing_invoice_id', $billingInvoiceIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (BillingInvoiceAuditLogModel $log): array => $log->toArray())
            ->all();
    }

    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (BillingInvoiceAuditLogModel $log): array => $log->toArray(),
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
