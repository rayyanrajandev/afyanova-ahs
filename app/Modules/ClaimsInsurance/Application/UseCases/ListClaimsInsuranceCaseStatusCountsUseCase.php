<?php

namespace App\Modules\ClaimsInsurance\Application\UseCases;

use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseRepositoryInterface;
use Illuminate\Support\Str;

class ListClaimsInsuranceCaseStatusCountsUseCase
{
    public function __construct(private readonly ClaimsInsuranceCaseRepositoryInterface $claimsInsuranceCaseRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $invoiceId = isset($filters['invoiceId']) ? trim((string) $filters['invoiceId']) : null;
        $invoiceId = $invoiceId === '' || ! Str::isUuid($invoiceId) ? null : $invoiceId;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' || ! Str::isUuid($patientId) ? null : $patientId;

        $allowedReconciliationStatuses = ['pending', 'partial_settled', 'settled'];
        $reconciliationStatus = isset($filters['reconciliationStatus']) ? strtolower(trim((string) $filters['reconciliationStatus'])) : null;
        if (! in_array($reconciliationStatus, $allowedReconciliationStatuses, true)) {
            $reconciliationStatus = null;
        }

        $allowedReconciliationExceptionStatuses = ['none', 'open', 'resolved'];
        $reconciliationExceptionStatus = isset($filters['reconciliationExceptionStatus'])
            ? strtolower(trim((string) $filters['reconciliationExceptionStatus']))
            : null;
        if (! in_array($reconciliationExceptionStatus, $allowedReconciliationExceptionStatuses, true)) {
            $reconciliationExceptionStatus = null;
        }

        $allowedPayerTypes = ['self_pay', 'insurance', 'employer', 'government', 'donor', 'other'];
        $payerType = isset($filters['payerType']) ? strtolower(trim((string) $filters['payerType'])) : null;
        if (! in_array($payerType, $allowedPayerTypes, true)) {
            $payerType = null;
        }

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->claimsInsuranceCaseRepository->statusCounts(
            query: $query,
            invoiceId: $invoiceId,
            patientId: $patientId,
            reconciliationStatus: $reconciliationStatus,
            reconciliationExceptionStatus: $reconciliationExceptionStatus,
            payerType: $payerType,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
