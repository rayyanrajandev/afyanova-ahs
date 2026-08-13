<?php

namespace App\Modules\ClaimsInsurance\Application\UseCases;

use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\ValueObjects\ClaimsInsuranceCaseStatus;
use Illuminate\Support\Str;

class ListClaimsInsuranceCasesUseCase
{
    public function __construct(private readonly ClaimsInsuranceCaseRepositoryInterface $claimsInsuranceCaseRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, ClaimsInsuranceCaseStatus::values(), true)) {
            $status = null;
        }

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

        $sortMap = [
            'claimNumber' => 'claim_number',
            'submittedAt' => 'submitted_at',
            'adjudicatedAt' => 'adjudicated_at',
            'settledAt' => 'settled_at',
            'status' => 'status',
            'reconciliationStatus' => 'reconciliation_status',
            'reconciliationExceptionStatus' => 'reconciliation_exception_status',
            'reconciliationFollowUpDueAt' => 'reconciliation_follow_up_due_at',
            'payerType' => 'payer_type',
            'claimAmount' => 'claim_amount',
            'approvedAmount' => 'approved_amount',
            'settledAmount' => 'settled_amount',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];

        $sortBy = $filters['sortBy'] ?? 'createdAt';
        $sortBy = $sortMap[$sortBy] ?? 'created_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $invoiceId = isset($filters['invoiceId']) ? trim((string) $filters['invoiceId']) : null;
        $invoiceId = $invoiceId === '' || ! Str::isUuid($invoiceId) ? null : $invoiceId;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' || ! Str::isUuid($patientId) ? null : $patientId;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->claimsInsuranceCaseRepository->search(
            query: $query,
            invoiceId: $invoiceId,
            patientId: $patientId,
            status: $status,
            reconciliationStatus: $reconciliationStatus,
            reconciliationExceptionStatus: $reconciliationExceptionStatus,
            payerType: $payerType,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
