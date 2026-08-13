<?php

namespace App\Modules\ClaimsInsurance\Application\UseCases;

use App\Modules\ClaimsInsurance\Application\Exceptions\ClaimsInsuranceReconciliationException;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseAuditLogRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\Repositories\ClaimsInsuranceCaseRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateClaimsInsuranceCaseReconciliationFollowUpUseCase
{
    public function __construct(
        private readonly ClaimsInsuranceCaseRepositoryInterface $claimsInsuranceCaseRepository,
        private readonly ClaimsInsuranceCaseAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $id,
        string $followUpStatus,
        ?string $followUpDueAt,
        ?string $followUpNote,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->claimsInsuranceCaseRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $normalizedFollowUpStatus = strtolower(trim($followUpStatus));
        $allowedStatuses = ['pending', 'in_progress', 'resolved', 'waived'];
        if (! in_array($normalizedFollowUpStatus, $allowedStatuses, true)) {
            throw new ClaimsInsuranceReconciliationException(
                'followUpStatus',
                'Follow-up status must be pending, in_progress, resolved, or waived.',
            );
        }

        $existingExceptionStatus = strtolower(trim((string) ($existing['reconciliation_exception_status'] ?? 'none')));
        if ($existingExceptionStatus !== 'open') {
            throw new ClaimsInsuranceReconciliationException(
                'followUpStatus',
                'Follow-up updates are allowed only when a reconciliation exception is open.',
            );
        }

        if (in_array($normalizedFollowUpStatus, ['pending', 'in_progress'], true)
            && ($followUpDueAt === null || trim($followUpDueAt) === '')
        ) {
            throw new ClaimsInsuranceReconciliationException(
                'followUpDueAt',
                'Follow-up due date is required when status is pending or in_progress.',
            );
        }

        $exceptionStatus = in_array($normalizedFollowUpStatus, ['resolved', 'waived'], true) ? 'resolved' : 'open';
        $payload = [
            'reconciliation_exception_status' => $exceptionStatus,
            'reconciliation_follow_up_status' => $normalizedFollowUpStatus,
            'reconciliation_follow_up_due_at' => in_array($normalizedFollowUpStatus, ['resolved', 'waived'], true)
                ? null
                : $followUpDueAt,
            'reconciliation_follow_up_note' => $followUpNote,
            'reconciliation_follow_up_updated_at' => now()->toDateTimeString(),
            'reconciliation_follow_up_updated_by_user_id' => $actorId,
        ];

        $updated = $this->claimsInsuranceCaseRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            claimsInsuranceCaseId: $id,
            action: 'claims-insurance-case.reconciliation-follow-up.updated',
            actorId: $actorId,
            changes: [
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
                'reconciliation_follow_up_note' => [
                    'before' => $existing['reconciliation_follow_up_note'] ?? null,
                    'after' => $updated['reconciliation_follow_up_note'] ?? null,
                ],
                'reconciliation_follow_up_updated_at' => [
                    'before' => $existing['reconciliation_follow_up_updated_at'] ?? null,
                    'after' => $updated['reconciliation_follow_up_updated_at'] ?? null,
                ],
                'reconciliation_follow_up_updated_by_user_id' => [
                    'before' => $existing['reconciliation_follow_up_updated_by_user_id'] ?? null,
                    'after' => $updated['reconciliation_follow_up_updated_by_user_id'] ?? null,
                ],
            ],
            metadata: [
                'exception_transition' => [
                    'from' => $existing['reconciliation_exception_status'] ?? null,
                    'to' => $updated['reconciliation_exception_status'] ?? null,
                ],
                'follow_up_transition' => [
                    'from' => $existing['reconciliation_follow_up_status'] ?? null,
                    'to' => $updated['reconciliation_follow_up_status'] ?? null,
                ],
                'open_exception_required' => true,
                'open_exception_present' => $existingExceptionStatus === 'open',
                'follow_up_due_at_required' => in_array($normalizedFollowUpStatus, ['pending', 'in_progress'], true),
                'follow_up_due_at_provided' => ($updated['reconciliation_follow_up_due_at'] ?? null) !== null,
                'follow_up_note_provided' => trim((string) ($updated['reconciliation_follow_up_note'] ?? '')) !== '',
                'closure_status' => in_array($normalizedFollowUpStatus, ['resolved', 'waived'], true),
            ],
        );

        return $updated;
    }
}
