<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use Illuminate\Support\Str;

class ListBillingInvoiceStatusCountsUseCase
{
    public function __construct(private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;
        if ($patientId !== null && ! Str::isUuid($patientId)) {
            $patientId = null;
        }

        $currencyCode = isset($filters['currencyCode']) ? strtoupper(trim((string) $filters['currencyCode'])) : null;
        $currencyCode = $currencyCode === '' ? null : $currencyCode;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        $paymentActivityFromDateTime = isset($filters['paymentActivityFrom']) ? trim((string) $filters['paymentActivityFrom']) : null;
        $paymentActivityFromDateTime = $paymentActivityFromDateTime === '' ? null : $paymentActivityFromDateTime;

        $paymentActivityToDateTime = isset($filters['paymentActivityTo']) ? trim((string) $filters['paymentActivityTo']) : null;
        $paymentActivityToDateTime = $paymentActivityToDateTime === '' ? null : $paymentActivityToDateTime;

        return $this->billingInvoiceRepository->statusCounts(
            query: $query,
            patientId: $patientId,
            currencyCode: $currencyCode,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            paymentActivityFromDateTime: $paymentActivityFromDateTime,
            paymentActivityToDateTime: $paymentActivityToDateTime,
        );
    }
}
