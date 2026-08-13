<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoicePaymentRepositoryInterface;
use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;

class ListBillingInvoicePaymentsUseCase
{
    public function __construct(
        private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository,
        private readonly BillingInvoicePaymentRepositoryInterface $billingInvoicePaymentRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}|null
     */
    public function execute(string $billingInvoiceId, array $filters): ?array
    {
        $invoice = $this->billingInvoiceRepository->findById($billingInvoiceId);
        if (! $invoice) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = max(min((int) ($filters['perPage'] ?? 20), 100), 1);
        $normalizedFilters = [
            'q' => $this->trimString($filters['q'] ?? null),
            'payerType' => $this->trimString($filters['payerType'] ?? null),
            'paymentMethod' => $this->trimString($filters['paymentMethod'] ?? null),
            'from' => $this->normalizeDateBoundary($filters['from'] ?? null, false),
            'to' => $this->normalizeDateBoundary($filters['to'] ?? null, true),
        ];

        return $this->billingInvoicePaymentRepository->listByBillingInvoiceId(
            billingInvoiceId: $billingInvoiceId,
            page: $page,
            perPage: $perPage,
            filters: $normalizedFilters,
        );
    }

    private function trimString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeDateBoundary(mixed $value, bool $endOfDay): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return null;
        }

        return $trimmed.($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }
}
