<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Application\Support\MedicationSafetyReviewGate;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderAuditLogRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Validation\ValidationException;

/**
 * Commits a drafted prescription.
 *
 * Pharmacy could open a draft and discard one, but had no way to sign it: the
 * route, the controller action and this use case were all missing, while both
 * diagnostic modules have had them since they were built. A drafted
 * prescription was therefore a dead end — the only exit was the bin.
 *
 * Deliberately identical in shape to SignLaboratoryOrderUseCase, because it is
 * the same act: the prescriber takes responsibility for the order and it
 * becomes real work for someone downstream.
 */
class SignPharmacyOrderUseCase
{
    public function __construct(
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly PharmacyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly MedicationSafetyReviewGate $safetyReviewGate,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(
        string $id,
        ?int $actorId = null,
        bool $safetyAcknowledged = false,
        ?string $safetyOverrideCode = null,
        ?string $safetyOverrideReason = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->pharmacyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        if (! ClinicalOrderLifecycle::isDraft($existing)) {
            throw ValidationException::withMessages([
                'order' => ['This pharmacy order is already signed.'],
            ]);
        }

        // Signing is what makes a prescription real, so it runs the same gate
        // CreatePharmacyOrderUseCase runs when an order is created active. A
        // draft skipped those checks on the way in precisely because it was not
        // yet an order; letting it through here unchecked would make drafting a
        // way around the interaction and allergy rules.
        if (blank($existing['clinical_indication'] ?? null)) {
            throw ValidationException::withMessages([
                'clinicalIndication' => [
                    'Clinical indication is required before this pharmacy order can become active.',
                ],
            ]);
        }

        $safetyReview = $this->safetyReviewGate->reviewOrFail(
            patientId: (string) $existing['patient_id'],
            context: [
                'medication_code' => $existing['medication_code'] ?? null,
                'medication_name' => $existing['medication_name'] ?? null,
                'dose_quantity' => $existing['dose_quantity'] ?? null,
                'dose_unit' => $existing['dose_unit'] ?? null,
                'route' => $existing['route'] ?? null,
                'frequency' => $existing['frequency'] ?? null,
                'duration_value' => $existing['duration_value'] ?? null,
                'duration_unit' => $existing['duration_unit'] ?? null,
                'clinical_indication' => $existing['clinical_indication'] ?? null,
                'quantity_prescribed' => $existing['quantity_prescribed'] ?? null,
                'prescribed_unit' => $existing['prescribed_unit'] ?? null,
                'dispensed_unit' => $existing['dispensed_unit'] ?? null,
                'appointment_id' => $existing['appointment_id'] ?? null,
                'admission_id' => $existing['admission_id'] ?? null,
                'formulary_decision_status' => $existing['formulary_decision_status'] ?? null,
            ],
            safetyAcknowledged: $safetyAcknowledged,
            safetyOverrideCode: $safetyOverrideCode,
            safetyOverrideReason: $safetyOverrideReason,
        );

        $payload = [];
        ClinicalOrderLifecycle::applyActiveEntryState($payload, $actorId);

        $updated = $this->pharmacyOrderRepository->update($id, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            pharmacyOrderId: $id,
            action: 'pharmacy-order.signed',
            actorId: $actorId,
            changes: [
                'entry_state' => [
                    'before' => $existing['entry_state'] ?? null,
                    'after' => $updated['entry_state'] ?? null,
                ],
                'signed_at' => [
                    'before' => $existing['signed_at'] ?? null,
                    'after' => $updated['signed_at'] ?? null,
                ],
                'signed_by_user_id' => [
                    'before' => $existing['signed_by_user_id'] ?? null,
                    'after' => $updated['signed_by_user_id'] ?? null,
                ],
                'lifecycle_locked_at' => [
                    'before' => $existing['lifecycle_locked_at'] ?? null,
                    'after' => $updated['lifecycle_locked_at'] ?? null,
                ],
            ],
            // Recorded exactly as creation records it, so a signed draft and an
            // order created active leave the same evidence of what was known and
            // acknowledged at the moment it became real.
            metadata: [
                'medication_safety_review' => [
                    'severity' => $safetyReview['severity'],
                    'blockers' => $safetyReview['blockers'],
                    'warnings' => $safetyReview['warnings'],
                    'rule_codes' => $safetyReview['ruleCodes'],
                    'rules' => $safetyReview['rules'],
                    'rule_groups' => $safetyReview['ruleGroups'],
                    'rule_catalog_version' => $safetyReview['ruleCatalogVersion'],
                    'suggested_actions' => $safetyReview['suggestedActions'],
                    'acknowledged' => $safetyAcknowledged,
                    'override_code' => $safetyReview['overrideCode'],
                    'override_option' => $safetyReview['overrideOption'],
                    'override_reason' => blank($safetyOverrideReason) ? null : $safetyOverrideReason,
                    'override_summary' => $safetyReview['overrideSummary'],
                ],
            ],
        );

        return $updated;
    }
}
