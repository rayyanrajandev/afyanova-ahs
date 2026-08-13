<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderApprovedMedicineCatalogItemNotEligibleException;
use App\Modules\Pharmacy\Application\Support\ApprovedMedicineGovernance;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderAuditLogRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Domain\Services\ApprovedMedicineCatalogLookupServiceInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdatePharmacyOrderUseCase
{
    /** Synonyms accepted for a volume dose unit, normalized to a canonical token. */
    private const VOLUME_UNIT_SYNONYMS = [
        'ml' => 'ml',
        'mls' => 'ml',
        'cc' => 'ml',
        'l' => 'l',
        'liter' => 'l',
        'liters' => 'l',
        'litre' => 'l',
        'litres' => 'l',
    ];

    /** Synonyms accepted for an infusion rate unit, normalized to a canonical token. */
    private const INFUSION_RATE_UNIT_SYNONYMS = [
        'ml/hr' => 'ml_per_hour',
        'ml/hour' => 'ml_per_hour',
        'ml per hour' => 'ml_per_hour',
        'mlph' => 'ml_per_hour',
        'cc/hr' => 'ml_per_hour',
        'cc/hour' => 'ml_per_hour',
        'ml_per_hour' => 'ml_per_hour',
    ];

    /** Synonyms accepted for an infusion duration unit, normalized to a canonical token. */
    private const INFUSION_DURATION_UNIT_SYNONYMS = [
        'hr' => 'hour',
        'hrs' => 'hour',
        'hour' => 'hour',
        'hours' => 'hour',
        'min' => 'minute',
        'mins' => 'minute',
        'minute' => 'minute',
        'minutes' => 'minute',
    ];

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

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'pharmacy order');

        $working = $existing;
        foreach ($payload as $field => $value) {
            $working[$field] = $value;
        }

        $catalogSelectionChanged = array_key_exists('approved_medicine_catalog_item_id', $payload)
            || array_key_exists('medication_code', $payload);

        if ($catalogSelectionChanged) {
            $selectedCatalogItem = $this->applyCatalogManagedApprovedMedicineSelection($working, $payload);
            foreach (ApprovedMedicineGovernance::draftPolicyDefaults($selectedCatalogItem) as $field => $value) {
                $working[$field] = $value;
            }
        }

        if (array_key_exists('quantity_prescribed', $working)) {
            $working['quantity_prescribed'] = round((float) $working['quantity_prescribed'], 2);
        }

        $this->normalizeStructuredDoseFields($working);

        $updatePayload = [];
        foreach ([
            'ordered_at',
            'dosage_instruction',
            'dose_quantity',
            'dose_unit',
            'route',
            'frequency',
            'duration_value',
            'duration_unit',
            'infusion_rate_value',
            'infusion_rate_unit',
            'infusion_duration_value',
            'infusion_duration_unit',
            'clinical_indication',
            'quantity_prescribed',
            'prescribed_unit',
            'dispensing_notes',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $updatePayload[$field] = $working[$field];
            }
        }

        if ($catalogSelectionChanged) {
            $this->applyDispenseUnitDefaults($working, $selectedCatalogItem);

            foreach ([
                'approved_medicine_catalog_item_id',
                'medication_code',
                'medication_name',
                'prescribed_unit',
                'dispensed_unit',
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
            ] as $field) {
                $updatePayload[$field] = $working[$field] ?? null;
            }
        }

        $updated = $this->pharmacyOrderRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            pharmacyOrderId: $id,
            action: 'pharmacy-order.updated',
            actorId: $actorId,
            changes: $this->collectChanges($existing, $updated, array_keys($updatePayload)),
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $incomingPayload
     */
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $incomingPayload
     * @return array<string, mixed>
     */
    private function applyCatalogManagedApprovedMedicineSelection(array &$payload, array $incomingPayload): array
    {
        $catalogItemId = array_key_exists('approved_medicine_catalog_item_id', $incomingPayload)
            ? trim((string) ($incomingPayload['approved_medicine_catalog_item_id'] ?? ''))
            : '';
        $medicationCode = array_key_exists('medication_code', $incomingPayload)
            ? trim((string) ($incomingPayload['medication_code'] ?? ''))
            : '';

        if ($catalogItemId === '' && $medicationCode === '') {
            $catalogItemId = trim((string) ($payload['approved_medicine_catalog_item_id'] ?? ''));
            $medicationCode = trim((string) ($payload['medication_code'] ?? ''));
        }

        $catalogItem = null;
        if ($catalogItemId !== '') {
            $catalogItem = $this->approvedMedicineCatalogLookupService->findActiveById($catalogItemId);
        } elseif ($medicationCode !== '') {
            $catalogItem = $this->approvedMedicineCatalogLookupService->findActiveByCode($medicationCode);
        }

        if ($catalogItem === null) {
            throw new PharmacyOrderApprovedMedicineCatalogItemNotEligibleException(
                'Selected approved medicine is not available in the active clinical catalog.'
            );
        }

        $resolvedCatalogItemId = trim((string) ($catalogItem['id'] ?? ''));
        $resolvedMedicationCode = trim((string) ($catalogItem['code'] ?? ''));
        $resolvedMedicationName = trim((string) ($catalogItem['name'] ?? ''));

        if ($resolvedCatalogItemId === '') {
            throw new PharmacyOrderApprovedMedicineCatalogItemNotEligibleException(
                'Selected approved medicine catalog entry is missing required identifier.'
            );
        }

        if ($resolvedMedicationCode === '' || $resolvedMedicationName === '') {
            throw new PharmacyOrderApprovedMedicineCatalogItemNotEligibleException(
                'Selected approved medicine catalog entry is missing required code or name.'
            );
        }

        if (strlen($resolvedMedicationCode) > 100) {
            throw new PharmacyOrderApprovedMedicineCatalogItemNotEligibleException(
                'Selected approved medicine code exceeds the supported pharmacy order length.'
            );
        }

        if (strlen($resolvedMedicationName) > 255) {
            throw new PharmacyOrderApprovedMedicineCatalogItemNotEligibleException(
                'Selected approved medicine name exceeds the supported pharmacy order length.'
            );
        }

        $payload['approved_medicine_catalog_item_id'] = $resolvedCatalogItemId;
        $payload['medication_code'] = $resolvedMedicationCode;
        $payload['medication_name'] = $resolvedMedicationName;

        return $catalogItem;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $catalogItem
     */
    private function applyDispenseUnitDefaults(array &$payload, array $catalogItem): void
    {
        $resolvedUnit = $this->normalizeUnit($payload['prescribed_unit'] ?? null)
            ?? $this->normalizeUnit($payload['dispensed_unit'] ?? null)
            ?? $this->normalizeUnit($catalogItem['unit'] ?? null);

        $payload['prescribed_unit'] = $this->normalizeUnit($payload['prescribed_unit'] ?? null) ?? $resolvedUnit;
        $payload['dispensed_unit'] = $this->normalizeUnit($payload['dispensed_unit'] ?? null)
            ?? $payload['prescribed_unit']
            ?? $resolvedUnit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizeStructuredDoseFields(array &$payload): void
    {
        foreach (['dose_unit', 'route', 'frequency', 'duration_unit', 'prescribed_unit'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->normalizeNullableString($payload[$field] ?? null);
            }
        }

        if (array_key_exists('dose_unit', $payload) && $payload['dose_unit'] !== null) {
            $payload['dose_unit'] = self::VOLUME_UNIT_SYNONYMS[$payload['dose_unit']] ?? $payload['dose_unit'];
        }

        if (array_key_exists('dose_quantity', $payload) && $payload['dose_quantity'] !== null && $payload['dose_quantity'] !== '') {
            $payload['dose_quantity'] = round((float) $payload['dose_quantity'], 4);
        }

        if (array_key_exists('duration_value', $payload) && $payload['duration_value'] !== null && $payload['duration_value'] !== '') {
            $payload['duration_value'] = round((float) $payload['duration_value'], 2);
        }

        $this->normalizeInfusionUnitField($payload, 'infusion_rate_unit', self::INFUSION_RATE_UNIT_SYNONYMS, 'Infusion rate unit');
        $this->normalizeInfusionUnitField($payload, 'infusion_duration_unit', self::INFUSION_DURATION_UNIT_SYNONYMS, 'Infusion duration unit');

        foreach (['infusion_rate_value', 'infusion_duration_value'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== '') {
                $payload[$field] = round((float) $payload[$field], 2);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $synonyms
     */
    private function normalizeInfusionUnitField(array &$payload, string $field, array $synonyms, string $label): void
    {
        if (! array_key_exists($field, $payload)) {
            return;
        }

        $raw = $this->normalizeNullableString($payload[$field] ?? null);
        if ($raw === null) {
            $payload[$field] = null;
            return;
        }

        $canonical = $synonyms[$raw] ?? null;
        if ($canonical === null) {
            $errorKey = Str::camel($field);
            throw ValidationException::withMessages([
                $errorKey => [
                    "{$label} \"{$raw}\" is not recognized. Expected one of: ".implode(', ', array_unique(array_values($synonyms))).'.',
                ],
            ]);
        }

        $payload[$field] = $canonical;
    }

    private function normalizeUnit(mixed $value): ?string
    {
        return $this->normalizeNullableString($value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_strtolower($normalized);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<int, string> $fields
     * @return array<string, array<string, mixed>>
     */
    private function collectChanges(array $before, array $after, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            $changes[$field] = [
                'before' => $before[$field] ?? null,
                'after' => $after[$field] ?? null,
            ];
        }

        return $changes;
    }
}
