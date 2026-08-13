<?php

namespace App\Modules\ClaimsInsurance\Application\UseCases;

use App\Modules\ClaimsInsurance\Application\Exceptions\ClaimsInsuranceReconciliationException;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseAuditLogRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\ValueObjects\ClaimsInsuranceCaseStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class ReconcileClaimsInsuranceCaseSettlementUseCase
{
    private const FOLLOW_UP_DEFAULT_DUE_DAYS = 14;

    public function __construct(
        private readonly ClaimsInsuranceCaseRepositoryInterface $claimsInsuranceCaseRepository,
        private readonly ClaimsInsuranceCaseAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $id,
        float $settledAmount,
        ?string $settledAt,
        ?string $settlementReference,
        ?string $reconciliationNotes,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->claimsInsuranceCaseRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $status = (string) ($existing['status'] ?? '');
        if (! in_array($status, [ClaimsInsuranceCaseStatus::APPROVED->value, ClaimsInsuranceCaseStatus::PARTIAL->value], true)) {
            throw new ClaimsInsuranceReconciliationException(
                'status',
                'Claim reconciliation is only allowed for approved or partial adjudication statuses.',
            );
        }

        $approvedAmount = round(max((float) ($existing['approved_amount'] ?? 0), 0), 2);
        if ($approvedAmount <= 0) {
            throw new ClaimsInsuranceReconciliationException(
                'approvedAmount',
                'Approved amount must be greater than zero before reconciliation can be recorded.',
            );
        }

        $normalizedSettledAmount = round(max($settledAmount, 0), 2);
        if ($normalizedSettledAmount > $approvedAmount) {
            throw new ClaimsInsuranceReconciliationException(
                'settledAmount',
                'Settled amount cannot exceed approved amount.',
            );
        }

        $reconciliationStatus = $this->resolveReconciliationStatus($normalizedSettledAmount, $approvedAmount);
        $shortfallAmount = round(max($approvedAmount - $normalizedSettledAmount, 0), 2);
        [$exceptionStatus, $followUpStatus, $followUpDueAt] = $this->resolveExceptionWorkflowState(
            reconciliationStatus: $reconciliationStatus,
            existing: $existing,
        );
        $followUpUpdatedAt = $reconciliationStatus === 'partial_settled' || $reconciliationStatus === 'settled'
            ? now()->toDateTimeString()
            : null;

        $payload = [
            'settled_amount' => $normalizedSettledAmount,
            'reconciliation_shortfall_amount' => $shortfallAmount,
            'settled_at' => $normalizedSettledAmount > 0
                ? ($settledAt ?? now()->toDateTimeString())
                : null,
            'settlement_reference' => $settlementReference,
            'reconciliation_status' => $reconciliationStatus,
            'reconciliation_exception_status' => $exceptionStatus,
            'reconciliation_follow_up_status' => $followUpStatus,
            'reconciliation_follow_up_due_at' => $followUpDueAt,
            'reconciliation_follow_up_updated_at' => $followUpUpdatedAt,
            'reconciliation_follow_up_updated_by_user_id' => $followUpUpdatedAt === null ? null : $actorId,
            'reconciliation_notes' => $reconciliationNotes,
        ];

        $updated = $this->claimsInsuranceCaseRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            claimsInsuranceCaseId: $id,
            action: 'claims-insurance-case.reconciliation.updated',
            actorId: $actorId,
            changes: [
                'settled_amount' => [
                    'before' => $existing['settled_amount'] ?? null,
                    'after' => $updated['settled_amount'] ?? null,
                ],
                'reconciliation_shortfall_amount' => [
                    'before' => $existing['reconciliation_shortfall_amount'] ?? null,
                    'after' => $updated['reconciliation_shortfall_amount'] ?? null,
                ],
                'settled_at' => [
                    'before' => $existing['settled_at'] ?? null,
                    'after' => $updated['settled_at'] ?? null,
                ],
                'settlement_reference' => [
                    'before' => $existing['settlement_reference'] ?? null,
                    'after' => $updated['settlement_reference'] ?? null,
                ],
                'reconciliation_status' => [
                    'before' => $existing['reconciliation_status'] ?? null,
                    'after' => $updated['reconciliation_status'] ?? null,
                ],
                'reconciliation_exception_status' => [
                    'before' => $existing['reconciliation_exception_status'] ?? null,
                    'after' => $updated['reconciliation_exception_status'] ?? null,
                ],
                'reconciliation_follow_up_status' => [
                    'before' => $existing['reconciliation_follow_up_status'] ?? null,
                    'after' => $updated['reconciliation_follow_up_status'] ?? null,
                ],
                'reconciliation_follow_up_due_at' => [
                    'before' => $existing['reconciliation_follow_up_due_at'] ?? null,
                    'after' => $updated['reconciliation_follow_up_due_at'] ?? null,
                ],
                'reconciliation_follow_up_updated_at' => [
                    'before' => $existing['reconciliation_follow_up_updated_at'] ?? null,
                    'after' => $updated['reconciliation_follow_up_updated_at'] ?? null,
                ],
                'reconciliation_follow_up_updated_by_user_id' => [
                    'before' => $existing['reconciliation_follow_up_updated_by_user_id'] ?? null,
                    'after' => $updated['reconciliation_follow_up_updated_by_user_id'] ?? null,
                ],
                'reconciliation_notes' => [
                    'before' => $existing['reconciliation_notes'] ?? null,
                    'after' => $updated['reconciliation_notes'] ?? null,
                ],
            ],
            metadata: [
                'reconciliation_transition' => [
                    'from' => $existing['reconciliation_status'] ?? null,
                    'to' => $updated['reconciliation_status'] ?? null,
                ],
                'exception_transition' => [
                    'from' => $existing['reconciliation_exception_status'] ?? null,
                    'to' => $updated['reconciliation_exception_status'] ?? null,
                ],
                'follow_up_transition' => [
                    'from' => $existing['reconciliation_follow_up_status'] ?? null,
                    'to' => $updated['reconciliation_follow_up_status'] ?? null,
                ],
                'adjudication_status' => $status,
                'adjudication_status_eligible' => in_array($status, [
                    ClaimsInsuranceCaseStatus::APPROVED->value,
                    ClaimsInsuranceCaseStatus::PARTIAL->value,
                ], true),
                'approved_amount_ceiling' => $approvedAmount,
                'settled_amount_submitted' => $normalizedSettledAmount,
                'settled_timestamp_required' => $normalizedSettledAmount > 0,
                'settled_timestamp_provided' => ($updated['settled_at'] ?? null) !== null,
                'settlement_reference_provided' => trim((string) ($updated['settlement_reference'] ?? '')) !== '',
                'reconciliation_notes_provided' => trim((string) ($updated['reconciliation_notes'] ?? '')) !== '',
                'shortfall_amount' => $shortfallAmount,
            ],
        );

        return $updated;
    }

    private function resolveReconciliationStatus(float $settledAmount, float $approvedAmount): string
    {
        if ($settledAmount <= 0) {
            return 'pending';
        }

        if ($settledAmount < $approvedAmount) {
            return 'partial_settled';
        }

        return 'settled';
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function resolveExceptionWorkflowState(string $reconciliationStatus, array $existing): array
    {
        if ($reconciliationStatus === 'settled') {
            $existingExceptionStatus = strtolower(trim((string) ($existing['reconciliation_exception_status'] ?? 'none')));
            if ($existingExceptionStatus === 'open') {
                return ['resolved', 'resolved', null];
            }

            return ['none', 'none', null];
        }

        if ($reconciliationStatus !== 'partial_settled') {
            return ['none', 'none', null];
        }

        $existingFollowUpStatus = strtolower(trim((string) ($existing['reconciliation_follow_up_status'] ?? '')));
        $followUpStatus = in_array($existingFollowUpStatus, ['pending', 'in_progress'], true)
            ? $existingFollowUpStatus
            : 'pending';

        $existingDueAt = $existing['reconciliation_follow_up_due_at'] ?? null;
        if ($followUpStatus === 'in_progress' && is_string($existingDueAt) && trim($existingDueAt) !== '') {
            return ['open', $followUpStatus, $existingDueAt];
        }

        return ['open', $followUpStatus, now()->addDays(self::FOLLOW_UP_DEFAULT_DUE_DAYS)->toDateTimeString()];
    }
}
