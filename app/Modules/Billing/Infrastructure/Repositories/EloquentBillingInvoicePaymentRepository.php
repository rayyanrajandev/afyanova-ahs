<?php

namespace App\Modules\Billing\Infrastructure\Repositories;

use App\Modules\Billing\Domain\Repositories\BillingInvoicePaymentRepositoryInterface;
use App\Modules\Billing\Infrastructure\Models\BillingInvoicePaymentModel;

class EloquentBillingInvoicePaymentRepository implements BillingInvoicePaymentRepositoryInterface
{
    public function create(array $attributes): array
    {
        return BillingInvoicePaymentModel::query()->create($attributes)->toArray();
    }

    public function findByIdForBillingInvoice(string $billingInvoiceId, string $paymentId): ?array
    {
        return BillingInvoicePaymentModel::query()
            ->where('billing_invoice_id', $billingInvoiceId)
            ->where('id', $paymentId)
            ->first()
            ?->toArray();
    }

    public function sumAppliedReversalsForPayment(string $paymentId): float
    {
        $sum = BillingInvoicePaymentModel::query()
            ->where('entry_type', 'reversal')
            ->where('reversal_of_payment_id', $paymentId)
            ->sum('amount');

        return round(abs((float) $sum), 2);
    }

    public function listByBillingInvoiceId(
        string $billingInvoiceId,
        int $page,
        int $perPage,
        array $filters = [],
    ): array
    {
        $query = BillingInvoicePaymentModel::query()
            ->where('billing_invoice_id', $billingInvoiceId);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function ($builder) use ($q): void {
                $builder
                    ->where('payment_reference', 'like', '%'.$q.'%')
                    ->orWhere('note', 'like', '%'.$q.'%')
                    ->orWhere('source_action', 'like', '%'.$q.'%');
            });
        }

        $payerType = trim((string) ($filters['payerType'] ?? ''));
        if ($payerType !== '') {
            $query->where('payer_type', $payerType);
        }

        $paymentMethod = trim((string) ($filters['paymentMethod'] ?? ''));
        if ($paymentMethod !== '') {
            $query->where('payment_method', $paymentMethod);
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $query->where('payment_at', '>=', $from);
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $query->where('payment_at', '<=', $to);
        }

        $paginator = $query
            ->orderByDesc('payment_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_map(
                static fn (BillingInvoicePaymentModel $payment): array => $payment->toArray(),
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

    public function listByBillingInvoiceIds(array $billingInvoiceIds): array
    {
        if ($billingInvoiceIds === []) {
            return [];
        }

        return BillingInvoicePaymentModel::query()
            ->whereIn('billing_invoice_id', $billingInvoiceIds)
            ->orderByDesc('payment_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (BillingInvoicePaymentModel $payment): array => $payment->toArray())
            ->all();
    }
}
