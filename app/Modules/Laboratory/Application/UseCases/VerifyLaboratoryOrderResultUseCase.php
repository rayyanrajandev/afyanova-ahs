<?php

namespace App\Modules\Laboratory\Application\UseCases;

use App\Modules\Laboratory\Application\Exceptions\LaboratoryOrderVerificationNotAllowedException;
use App\Modules\Laboratory\Application\Services\RecordLaboratoryFlowTransitionService;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderAuditLogRepositoryInterface;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderRepositoryInterface;
use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class VerifyLaboratoryOrderResultUseCase
{
    public function __construct(
        private readonly LaboratoryOrderRepositoryInterface $laboratoryOrderRepository,
        private readonly LaboratoryOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly RecordLaboratoryFlowTransitionService $recordFlowTransition,
    ) {}

    public function execute(string $id, ?string $verificationNote, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->laboratoryOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'laboratory order');

        // Two-person rule, enforced before anything is written.
        //
        // This lived in the controller and ran *after* execute() had already
        // committed the verification and recorded the flow transition, so the
        // caller got a 422 while the database said the result was verified and
        // released. Checking who the actor is costs nothing and belongs with
        // the other preconditions, ahead of every side effect.
        if ($this->isOwnOrder($existing, $actorId)) {
            throw new LaboratoryOrderVerificationNotAllowedException(
                'You cannot verify your own laboratory order.'
            );
        }

        if (($existing['status'] ?? null) !== LaboratoryOrderStatus::COMPLETED->value) {
            throw new LaboratoryOrderVerificationNotAllowedException(
                'Only completed laboratory orders can be verified.'
            );
        }

        if (empty($existing['result_summary'])) {
            throw new LaboratoryOrderVerificationNotAllowedException(
                'Laboratory result summary is required before verification.'
            );
        }

        if (! empty($existing['verified_at'])) {
            throw new LaboratoryOrderVerificationNotAllowedException(
                'Laboratory result is already verified.'
            );
        }

        $isCriticalResult = $this->isCriticalResultSummary((string) ($existing['result_summary'] ?? ''));
        if ($isCriticalResult
            && blank($verificationNote)) {
            throw new LaboratoryOrderVerificationNotAllowedException(
                'Verification note is required for critical laboratory results.'
            );
        }

        $payload = [
            'verified_at' => now(),
            'verified_by_user_id' => $actorId,
            'verification_note' => $verificationNote,
        ];

        $updated = $this->laboratoryOrderRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            laboratoryOrderId: $id,
            action: 'laboratory-order.result.verified',
            actorId: $actorId,
            changes: [
                'verified_at' => [
                    'before' => $existing['verified_at'] ?? null,
                    'after' => $updated['verified_at'] ?? null,
                ],
                'verified_by_user_id' => [
                    'before' => $existing['verified_by_user_id'] ?? null,
                    'after' => $updated['verified_by_user_id'] ?? null,
                ],
                'verification_note' => [
                    'before' => $existing['verification_note'] ?? null,
                    'after' => $updated['verification_note'] ?? null,
                ],
            ],
            metadata: [
                'critical_result' => $isCriticalResult,
                'verification_note_required' => $isCriticalResult,
                'verification_note_provided' => ! blank($verificationNote),
            ],
        );

        // Decision 1 of the laboratory flow plan: verification is where the lab
        // hands the visit back. recordForOrder() re-resolves across every open
        // order first, so a visit with other labs still running stays put —
        // the step belongs to the visit, not to this order.
        $this->recordFlowTransition->recordForOrder(
            order: $updated,
            source: 'laboratory.result_verified',
            actorId: $actorId,
            isVerification: true,
            metadata: [
                'laboratory_order_id' => $id,
                'critical_result' => $isCriticalResult,
            ],
        );

        return $updated;
    }

    private function isCriticalResultSummary(string $resultSummary): bool
    {
        return str_contains(strtolower($resultSummary), 'result flag: critical');
    }

    /**
     * Whether the actor is the same user the order was placed by.
     *
     * Compared as strings because the ordering user id reaches us through the
     * repository array, where the driver may hand back an int or a numeric
     * string; a strict comparison silently passed whenever the types differed.
     *
     * @param  array<string, mixed>  $order
     */
    private function isOwnOrder(array $order, ?int $actorId): bool
    {
        $orderedBy = $order['ordered_by_user_id'] ?? null;

        if ($orderedBy === null || $actorId === null) {
            return false;
        }

        return (string) $orderedBy === (string) $actorId;
    }
}
