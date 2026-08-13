<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderVerificationNotAllowedException;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderAuditLogRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class VerifyPharmacyOrderDispenseUseCase
{
    public function __construct(
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly PharmacyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, ?string $verificationNote, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->pharmacyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertActiveForWorkflow($existing, 'pharmacy order');

        if (($existing['status'] ?? null) !== PharmacyOrderStatus::DISPENSED->value) {
            throw new PharmacyOrderVerificationNotAllowedException(
                'Only dispensed pharmacy orders can be verified.'
            );
        }

        if (empty($existing['dispensed_at'])) {
            throw new PharmacyOrderVerificationNotAllowedException(
                'Dispense timestamp is required before verification.'
            );
        }

        if ($this->orderIndicatesSubstitution($existing)
            && blank($verificationNote)) {
            throw new PharmacyOrderVerificationNotAllowedException(
                'Verification note is required when a substitution was dispensed.'
            );
        }

        $verificationNoteRequired = $this->orderIndicatesSubstitution($existing);
        $workflowStatusSatisfied = ($existing['status'] ?? null) === PharmacyOrderStatus::DISPENSED->value;
        $dispensedTimestampProvided = ! empty($existing['dispensed_at'] ?? null);
        $verificationNoteProvided = trim((string) ($verificationNote ?? '')) !== '';

        $updated = $this->pharmacyOrderRepository->update($id, [
            'verified_at' => now(),
            'verified_by_user_id' => $actorId,
            'verification_note' => $verificationNote,
        ]);

        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            pharmacyOrderId: $id,
            action: 'pharmacy-order.dispense.verified',
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
                'transition' => [
                    'verified_at_from' => $existing['verified_at'] ?? null,
                    'verified_at_to' => $updated['verified_at'] ?? null,
                ],
                'workflow_status_required' => PharmacyOrderStatus::DISPENSED->value,
                'workflow_status_satisfied' => $workflowStatusSatisfied,
                'dispensed_timestamp_required' => true,
                'dispensed_timestamp_provided' => $dispensedTimestampProvided,
                'verification_note_required' => $verificationNoteRequired,
                'verification_note_provided' => $verificationNoteProvided,
            ],
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function orderIndicatesSubstitution(array $order): bool
    {
        if ((bool) ($order['substitution_made'] ?? false)) {
            return true;
        }

        $dispensingNotes = (string) ($order['dispensing_notes'] ?? '');

        return str_contains(strtolower($dispensingNotes), 'substitution: yes');
    }
}
