<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Radiology\Application\Exceptions\RadiologyOrderVerificationNotAllowedException;
use App\Modules\Radiology\Application\Services\RecordRadiologyFlowTransitionService;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderAuditLogRepositoryInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderRepositoryInterface;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

/**
 * Releases a completed radiology report to the patient chart.
 *
 * Radiology had no verification step at all: `completed` was the end of the
 * line, so a report was visible to the clinician the moment the radiographer
 * saved it. This use case adds the second pair of eyes, and deliberately mirrors
 * VerifyLaboratoryOrderResultUseCase so both diagnostic modules enforce the same
 * rules in the same order.
 *
 * Every precondition runs *before* the write. The laboratory equivalent checked
 * the two-person rule in its controller, after execute() had already committed —
 * the caller got a 422 over a verification the database had accepted. That
 * ordering mistake is not repeated here.
 */
class VerifyRadiologyOrderResultUseCase
{
    public function __construct(
        private readonly RadiologyOrderRepositoryInterface $radiologyOrderRepository,
        private readonly RadiologyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly RecordRadiologyFlowTransitionService $recordFlowTransition,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $id, ?string $verificationNote, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->radiologyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'radiology order');

        // Two-person rule: whoever requested the study may not sign off its
        // report. RadiologyOrderPolicy::verifyResult already expressed this, but
        // nothing ever dispatched it — the policy takes a model while no caller
        // existed at all.
        if ($this->isOwnOrder($existing, $actorId)) {
            throw new RadiologyOrderVerificationNotAllowedException(
                'You cannot verify your own radiology order.'
            );
        }

        if (($existing['status'] ?? null) !== RadiologyOrderStatus::COMPLETED->value) {
            throw new RadiologyOrderVerificationNotAllowedException(
                'Only completed radiology orders can be verified.'
            );
        }

        if (empty($existing['report_summary'])) {
            throw new RadiologyOrderVerificationNotAllowedException(
                'Radiology report summary is required before verification.'
            );
        }

        if (! empty($existing['verified_at'])) {
            throw new RadiologyOrderVerificationNotAllowedException(
                'Radiology report is already verified.'
            );
        }

        $isCriticalResult = $this->isCriticalReportSummary((string) ($existing['report_summary'] ?? ''));
        if ($isCriticalResult && blank($verificationNote)) {
            throw new RadiologyOrderVerificationNotAllowedException(
                'Verification note is required for critical radiology findings.'
            );
        }

        $updated = $this->radiologyOrderRepository->update($id, [
            'verified_at' => now(),
            'verified_by_user_id' => $actorId,
            'verification_note' => $verificationNote,
        ]);

        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            radiologyOrderId: $id,
            action: 'radiology-order.result.verified',
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

        // Verification is where imaging hands the visit back. recordForOrder()
        // re-resolves across every open order first, so a visit with another
        // study or a lab still running stays put — the step belongs to the
        // visit, not to this order.
        $this->recordFlowTransition->recordForOrder(
            order: $updated,
            source: 'radiology.result_verified',
            actorId: $actorId,
            isVerification: true,
            metadata: [
                'radiology_order_id' => $id,
                'critical_result' => $isCriticalResult,
            ],
        );

        return $updated;
    }

    /**
     * Radiology's twin of the laboratory critical-result convention: the phrase
     * is written into the report summary by whoever enters the report, and makes
     * the verification note mandatory.
     */
    private function isCriticalReportSummary(string $reportSummary): bool
    {
        return str_contains(strtolower($reportSummary), 'result flag: critical');
    }

    /**
     * Compared as strings because the ordering user id arrives through the
     * repository array, where the driver may return an int or a numeric string;
     * a strict comparison silently passes whenever the types differ.
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
