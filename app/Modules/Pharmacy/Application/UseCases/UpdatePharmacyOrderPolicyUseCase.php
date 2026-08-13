<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderPolicyUpdateNotAllowedException;
use App\Modules\Pharmacy\Application\Support\ApprovedMedicineGovernance;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderAuditLogRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Domain\Services\ApprovedMedicineCatalogLookupServiceInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Validation\ValidationException;

class UpdatePharmacyOrderPolicyUseCase
{
    public function __construct(
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly PharmacyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly ApprovedMedicineCatalogLookupServiceInterface $approvedMedicineCatalogLookupService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->pharmacyOrderRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $substitutionMade = (bool) ($payload['substitution_made'] ?? false);
        $substitutionAllowed = (bool) ($payload['substitution_allowed'] ?? false);
        if ($substitutionMade && ! $substitutionAllowed) {
            throw new PharmacyOrderPolicyUpdateNotAllowedException('Substitution cannot be marked as made when substitution is not allowed.');
        }

        $requestedCatalogItem = $this->resolveRequestedCatalogItem($existing);
        $this->validateDecisionReason($existing, $payload, $requestedCatalogItem);
        $this->validateSubstitutionTarget($existing, $payload);

        $updatePayload = [
            'formulary_decision_status' => (string) $payload['formulary_decision_status'],
            'formulary_decision_reason' => $payload['formulary_decision_reason'] ?? null,
            'formulary_reviewed_at' => now(),
            'formulary_reviewed_by_user_id' => $actorId,
            'substitution_allowed' => $substitutionAllowed,
            'substitution_made' => $substitutionMade,
            'substituted_medication_code' => $substitutionMade ? ($payload['substituted_medication_code'] ?? null) : null,
            'substituted_medication_name' => $substitutionMade ? ($payload['substituted_medication_name'] ?? null) : null,
            'substitution_reason' => $substitutionMade ? ($payload['substitution_reason'] ?? null) : null,
            'substitution_approved_at' => $substitutionMade ? now() : null,
            'substitution_approved_by_user_id' => $substitutionMade ? $actorId : null,
        ];

        $updated = $this->pharmacyOrderRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        $this->auditLogRepository->write(
            pharmacyOrderId: $id,
            action: 'pharmacy-order.policy.updated',
            actorId: $actorId,
            changes: $changes === [] ? ['after' => $updated] : $changes,
        );

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'formulary_decision_status',
            'formulary_decision_reason',
            'formulary_reviewed_at',
            'formulary_reviewed_by_user_id',
            'substitution_allowed',
            'substitution_made',
            'substituted_medication_code',
            'substituted_medication_name',
            'substitution_reason',
            'substitution_approved_at',
            'substitution_approved_by_user_id',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }
            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    private function resolveRequestedCatalogItem(array $order): ?array
    {
        $catalogItemId = trim((string) ($order['approved_medicine_catalog_item_id'] ?? ''));
        if ($catalogItemId !== '') {
            return $this->approvedMedicineCatalogLookupService->findActiveById($catalogItemId);
        }

        $medicationCode = trim((string) ($order['medication_code'] ?? ''));
        if ($medicationCode !== '') {
            return $this->approvedMedicineCatalogLookupService->findActiveByCode($medicationCode);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $catalogItem
     */
    private function validateDecisionReason(array $existing, array $payload, ?array $catalogItem): void
    {
        $decisionStatus = strtolower(trim((string) ($payload['formulary_decision_status'] ?? 'not_reviewed')));
        $reason = trim((string) ($payload['formulary_decision_reason'] ?? ''));
        $clinicalIndication = (string) ($existing['clinical_indication'] ?? '');

        if (
            $decisionStatus === 'formulary'
            && ApprovedMedicineGovernance::requiresPolicyReview($catalogItem)
            && ApprovedMedicineGovernance::indicationNeedsClarification($catalogItem, $clinicalIndication)
            && $reason === ''
        ) {
            throw ValidationException::withMessages([
                'formularyDecisionReason' => [
                    'Document why this restricted medicine can be approved when the recorded indication still needs clarification.',
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $payload
     */
    private function validateSubstitutionTarget(array $existing, array $payload): void
    {
        if (! (bool) ($payload['substitution_made'] ?? false)) {
            return;
        }

        $requestedCode = $this->normalizeText($existing['medication_code'] ?? null);
        $requestedName = $this->normalizeText($existing['medication_name'] ?? null);
        $substitutedCode = $this->normalizeText($payload['substituted_medication_code'] ?? null);
        $substitutedName = $this->normalizeText($payload['substituted_medication_name'] ?? null);

        $sameCode = $requestedCode !== '' && $requestedCode === $substitutedCode;
        $sameName = $requestedName !== '' && $requestedName === $substitutedName;

        if ($sameCode || $sameName) {
            throw ValidationException::withMessages([
                'substitutedMedicationCode' => [
                    'Select a different medicine for substitution. The current substitute matches the original order item.',
                ],
            ]);
        }
    }

    private function normalizeText(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }
}
