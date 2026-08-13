<?php

namespace App\Modules\ClaimsInsurance\Application\UseCases;

use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseAuditLogRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\ValueObjects\ClaimsInsuranceCaseStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateClaimsInsuranceCaseStatusUseCase
{
    public function __construct(
        private readonly ClaimsInsuranceCaseRepositoryInterface $claimsInsuranceCaseRepository,
        private readonly ClaimsInsuranceCaseAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $id,
        string $status,
        ?string $reason,
        ?string $decisionReason,
        ?string $submittedAt,
        ?string $adjudicatedAt,
        ?float $approvedAmount,
        ?float $rejectedAmount,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->claimsInsuranceCaseRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $payload = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        if ($decisionReason !== null) {
            $payload['decision_reason'] = $decisionReason;
        }

        if ($status === ClaimsInsuranceCaseStatus::SUBMITTED->value) {
            $payload['submitted_at'] = $submittedAt ?? now();
        }

        if (in_array($status, [
            ClaimsInsuranceCaseStatus::APPROVED->value,
            ClaimsInsuranceCaseStatus::REJECTED->value,
            ClaimsInsuranceCaseStatus::PARTIAL->value,
        ], true)) {
            $payload['adjudicated_at'] = $adjudicatedAt ?? now();
        }

        if ($approvedAmount !== null) {
            $payload['approved_amount'] = $approvedAmount;
        }

        if ($rejectedAmount !== null) {
            $payload['rejected_amount'] = $rejectedAmount;
        }

        $updated = $this->claimsInsuranceCaseRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            claimsInsuranceCaseId: $id,
            action: 'claims-insurance-case.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
                'decision_reason' => [
                    'before' => $existing['decision_reason'] ?? null,
                    'after' => $updated['decision_reason'] ?? null,
                ],
                'submitted_at' => [
                    'before' => $existing['submitted_at'] ?? null,
                    'after' => $updated['submitted_at'] ?? null,
                ],
                'adjudicated_at' => [
                    'before' => $existing['adjudicated_at'] ?? null,
                    'after' => $updated['adjudicated_at'] ?? null,
                ],
                'approved_amount' => [
                    'before' => $existing['approved_amount'] ?? null,
                    'after' => $updated['approved_amount'] ?? null,
                ],
                'rejected_amount' => [
                    'before' => $existing['rejected_amount'] ?? null,
                    'after' => $updated['rejected_amount'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => in_array($status, [
                    ClaimsInsuranceCaseStatus::REJECTED->value,
                    ClaimsInsuranceCaseStatus::PARTIAL->value,
                    ClaimsInsuranceCaseStatus::CANCELLED->value,
                ], true),
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                'decision_reason_required' => in_array($status, [
                    ClaimsInsuranceCaseStatus::REJECTED->value,
                    ClaimsInsuranceCaseStatus::PARTIAL->value,
                ], true),
                'decision_reason_provided' => trim((string) ($updated['decision_reason'] ?? '')) !== '',
                'submitted_timestamp_required' => $status === ClaimsInsuranceCaseStatus::SUBMITTED->value,
                'submitted_timestamp_provided' => ($updated['submitted_at'] ?? null) !== null,
                'adjudicated_timestamp_required' => in_array($status, [
                    ClaimsInsuranceCaseStatus::APPROVED->value,
                    ClaimsInsuranceCaseStatus::REJECTED->value,
                    ClaimsInsuranceCaseStatus::PARTIAL->value,
                ], true),
                'adjudicated_timestamp_provided' => ($updated['adjudicated_at'] ?? null) !== null,
                'approved_amount_required' => in_array($status, [
                    ClaimsInsuranceCaseStatus::APPROVED->value,
                    ClaimsInsuranceCaseStatus::PARTIAL->value,
                ], true),
                'approved_amount_provided' => ($updated['approved_amount'] ?? null) !== null,
                'rejected_amount_required' => in_array($status, [
                    ClaimsInsuranceCaseStatus::REJECTED->value,
                    ClaimsInsuranceCaseStatus::PARTIAL->value,
                ], true),
                'rejected_amount_provided' => ($updated['rejected_amount'] ?? null) !== null,
            ],
        );

        return $updated;
    }
}
