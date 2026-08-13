<?php

namespace App\Modules\Laboratory\Application\UseCases;

use App\Modules\Laboratory\Application\Exceptions\LaboratoryOrderVerificationNotAllowedException;
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
    ) {}

    public function execute(string $id, ?string $verificationNote, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->laboratoryOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'laboratory order');

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

        return $updated;
    }

    private function isCriticalResultSummary(string $resultSummary): bool
    {
        return str_contains(strtolower($resultSummary), 'result flag: critical');
    }
}
