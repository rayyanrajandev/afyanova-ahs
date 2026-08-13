<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;

class GetBillingFinancialControlSummaryUseCase
{
    public function __construct(private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository) {}

    public function execute(array $filters): array
    {
        $currencyCode = isset($filters['currencyCode']) ? strtoupper(trim((string) $filters['currencyCode'])) : null;
        $currencyCode = $currencyCode === '' ? null : $currencyCode;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        $allowedPayerTypes = ['self_pay', 'insurance', 'employer', 'government', 'donor', 'other'];
        $payerType = isset($filters['payerType']) ? strtolower(trim((string) $filters['payerType'])) : null;
        if (! in_array($payerType, $allowedPayerTypes, true)) {
            $payerType = null;
        }

        $asOfDateTime = isset($filters['asOf']) ? trim((string) $filters['asOf']) : null;
        $asOfDateTime = $asOfDateTime === '' ? null : $asOfDateTime;

        $departmentFilter = isset($filters['departmentId']) ? trim((string) $filters['departmentId']) : null;
        if ($departmentFilter === null || $departmentFilter === '') {
            $departmentFilter = isset($filters['department']) ? trim((string) $filters['department']) : null;
        }
        $departmentFilter = $departmentFilter === '' ? null : $departmentFilter;

        return $this->billingInvoiceRepository->financialControlSummary(
            currencyCode: $currencyCode,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            payerType: $payerType,
            asOfDateTime: $asOfDateTime,
            departmentFilter: $departmentFilter,
        );
    }
}
