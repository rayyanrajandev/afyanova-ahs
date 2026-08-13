<?php

namespace App\Modules\Pos\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Pos\Domain\Repositories\PosSaleRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosSalePaymentMethod;
use App\Modules\Pos\Infrastructure\Models\PosSaleModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentPosSaleRepository implements PosSaleRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $saleAttributes, array $lineItems, array $payments): array
    {
        $sale = new PosSaleModel();
        $sale->fill($saleAttributes);
        $sale->save();

        $sale->lineItems()->createMany($lineItems);
        $sale->payments()->createMany($payments);

        return $this->findById((string) $sale->id) ?? $sale->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = PosSaleModel::query()->with(['register', 'session', 'lineItems', 'payments', 'adjustments']);
        $this->applyPlatformScopeIfEnabled($query);

        $sale = $query->find($id);

        return $sale?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = PosSaleModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $sale = $query->find($id);
        if ($sale === null) {
            return null;
        }

        $sale->fill($attributes);
        $sale->save();

        return $this->findById($id) ?? $sale->toArray();
    }

    public function existsBySaleNumber(string $saleNumber): bool
    {
        return PosSaleModel::query()
            ->where('sale_number', trim($saleNumber))
            ->exists();
    }

    public function existsByReceiptNumber(string $receiptNumber): bool
    {
        return PosSaleModel::query()
            ->where('receipt_number', trim($receiptNumber))
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $registerId,
        ?string $sessionId,
        ?string $paymentMethod,
        ?string $saleChannel,
        ?string $status,
        ?string $soldFrom,
        ?string $soldTo,
        int $page,
        int $perPage
    ): array {
        $queryBuilder = PosSaleModel::query()->with(['register', 'session', 'adjustments']);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('sale_number', 'like', $like)
                        ->orWhere('receipt_number', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_reference', 'like', $like);
                });
            })
            ->when($registerId, fn (Builder $builder, string $value) => $builder->where('pos_register_id', $value))
            ->when($sessionId, fn (Builder $builder, string $value) => $builder->where('pos_register_session_id', $value))
            ->when($paymentMethod, function (Builder $builder, string $value): void {
                $builder->whereHas('payments', fn (Builder $paymentQuery) => $paymentQuery->where('payment_method', $value));
            })
            ->when($saleChannel, fn (Builder $builder, string $value) => $builder->where('sale_channel', $value))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($soldFrom, fn (Builder $builder, string $value) => $builder->where('sold_at', '>=', $value))
            ->when($soldTo, fn (Builder $builder, string $value) => $builder->where('sold_at', '<=', $value))
            ->orderByDesc('sold_at');

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function summarizeSession(string $sessionId): array
    {
        $query = PosSaleModel::query()
            ->with('payments')
            ->where('pos_register_session_id', $sessionId);
        $this->applyPlatformScopeIfEnabled($query);

        $sales = $query->get();

        $summary = [
            'saleCount' => 0,
            'grossSalesAmount' => 0.0,
            'totalDiscountAmount' => 0.0,
            'totalTaxAmount' => 0.0,
            'cashReceivedAmount' => 0.0,
            'cashChangeAmount' => 0.0,
            'cashNetSalesAmount' => 0.0,
            'nonCashSalesAmount' => 0.0,
        ];

        foreach ($sales as $sale) {
            $summary['saleCount']++;
            $summary['grossSalesAmount'] += (float) $sale->total_amount;
            $summary['totalDiscountAmount'] += (float) $sale->discount_amount;
            $summary['totalTaxAmount'] += (float) $sale->tax_amount;

            foreach ($sale->payments as $payment) {
                $method = (string) $payment->payment_method;
                $amountReceived = (float) $payment->amount_received;
                $amountApplied = (float) $payment->amount_applied;
                $changeGiven = (float) $payment->change_given;

                if ($method === PosSalePaymentMethod::CASH->value) {
                    $summary['cashReceivedAmount'] += $amountReceived;
                    $summary['cashChangeAmount'] += $changeGiven;
                    $summary['cashNetSalesAmount'] += max($amountReceived - $changeGiven, 0);
                } else {
                    $summary['nonCashSalesAmount'] += $amountApplied;
                }
            }
        }

        return array_map(
            static fn (float|int $value): float|int => is_float($value) ? round($value, 2) : $value,
            $summary,
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

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (PosSaleModel $sale): array => $sale->toArray(),
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
