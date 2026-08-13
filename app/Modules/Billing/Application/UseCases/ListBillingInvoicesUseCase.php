<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\Billing\Domain\ValueObjects\BillingInvoiceStatus;

class ListBillingInvoicesUseCase
{
    public function __construct(private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, BillingInvoiceStatus::values(), true)) {
            $status = null;
        }

        $statuses = $filters['statusIn'] ?? null;
        if (is_string($statuses)) {
            $statuses = explode(',', $statuses);
        }
        if (! is_array($statuses)) {
            $statuses = null;
        }

        if (is_array($statuses)) {
            $statuses = array_values(array_unique(array_filter(
                array_map(static fn ($value) => is_string($value) ? trim($value) : null, $statuses),
                static fn ($value) => is_string($value) && in_array($value, BillingInvoiceStatus::values(), true),
            )));
            $statuses = $statuses === [] ? null : $statuses;
        }

        // Explicit multi-status filter takes precedence over the single-status filter.
        if ($statuses !== null) {
            $status = null;
        }

        $currencyCode = isset($filters['currencyCode']) ? strtoupper(trim((string) $filters['currencyCode'])) : null;
        $currencyCode = $currencyCode === '' ? null : $currencyCode;

        $sortMap = [
            'invoiceNumber' => 'invoice_number',
            'invoiceDate' => 'invoice_date',
            'totalAmount' => 'total_amount',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];

        $sortBy = $filters['sortBy'] ?? 'invoiceDate';
        $sortBy = $sortMap[$sortBy] ?? 'invoice_date';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        $paymentActivityFromDateTime = isset($filters['paymentActivityFrom']) ? trim((string) $filters['paymentActivityFrom']) : null;
        $paymentActivityFromDateTime = $paymentActivityFromDateTime === '' ? null : $paymentActivityFromDateTime;

        $paymentActivityToDateTime = isset($filters['paymentActivityTo']) ? trim((string) $filters['paymentActivityTo']) : null;
        $paymentActivityToDateTime = $paymentActivityToDateTime === '' ? null : $paymentActivityToDateTime;

        return $this->billingInvoiceRepository->search(
            query: $query,
            patientId: $patientId,
            status: $status,
            statuses: $statuses,
            currencyCode: $currencyCode,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            paymentActivityFromDateTime: $paymentActivityFromDateTime,
            paymentActivityToDateTime: $paymentActivityToDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
