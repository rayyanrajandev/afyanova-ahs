<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Application\Support\ApprovedMedicineGovernance;
use App\Modules\Pharmacy\Application\Support\MedicationSafetyRuleCatalog;
use App\Modules\Pharmacy\Application\Support\MedicationSafetyReviewGate;
use App\Modules\Pharmacy\Application\Exceptions\AdmissionNotEligibleForPharmacyOrderException;
use App\Modules\Pharmacy\Application\Exceptions\AppointmentNotEligibleForPharmacyOrderException;
use App\Modules\Pharmacy\Application\Exceptions\PatientNotEligibleForPharmacyOrderException;
use App\Modules\Pharmacy\Application\Exceptions\PharmacyOrderApprovedMedicineCatalogItemNotEligibleException;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderAuditLogRepositoryInterface;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Domain\Services\AdmissionLookupServiceInterface;
use App\Modules\Pharmacy\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Pharmacy\Domain\Services\ApprovedMedicineCatalogLookupServiceInterface;
use App\Modules\Pharmacy\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\ServiceRequest\Application\UseCases\LinkServiceRequestToClinicalOrderUseCase;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestServiceType;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use App\Support\ClinicalOrders\OrderSessionManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreatePharmacyOrderUseCase
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
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly AdmissionLookupServiceInterface $admissionLookupService,
        private readonly ApprovedMedicineCatalogLookupServiceInterface $approvedMedicineCatalogLookupService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly OrderSessionManager $orderSessionManager,
        private readonly MedicationSafetyReviewGate $medicationSafetyReviewGate,
        private readonly LinkServiceRequestToClinicalOrderUseCase $serviceRequestLinker,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $patientId = (string) $payload['patient_id'];
        if (! $this->patientLookupService->patientExists($patientId)) {
            throw new PatientNotEligibleForPharmacyOrderException(
                'Pharmacy order can only be created for an existing patient.',
            );
        }

        $serviceRequestId = trim((string) ($payload['service_request_id'] ?? ''));
        unset($payload['service_request_id']);
        if ($serviceRequestId !== '') {
            $this->serviceRequestLinker->assertLinkable($serviceRequestId, $patientId, 'pharmacy');
        }

        $appointmentId = $payload['appointment_id'] ?? null;
        if ($appointmentId !== null && ! $this->appointmentLookupService->isValidForPatient((string) $appointmentId, $patientId)) {
            throw new AppointmentNotEligibleForPharmacyOrderException(
                'Appointment is not valid for the selected patient.',
            );
        }

        $admissionId = $payload['admission_id'] ?? null;
        if ($admissionId !== null && ! $this->admissionLookupService->isValidForPatient((string) $admissionId, $patientId)) {
            throw new AdmissionNotEligibleForPharmacyOrderException(
                'Admission is not valid for the selected patient.',
            );
        }

        $selectedCatalogItem = $this->applyCatalogManagedApprovedMedicineSelection($payload);
        if (!empty($payload['dose_quantity']) && !empty($payload['dose_unit'])) {
            $this->validateStructuredDoseFields($payload, $selectedCatalogItem);
        }
        foreach (ApprovedMedicineGovernance::draftPolicyDefaults($selectedCatalogItem) as $field => $value) {
            $payload[$field] = $value;
        }
        $safetyAcknowledged = (bool) ($payload['safety_acknowledged'] ?? false);
        $safetyOverrideCode = trim((string) ($payload['safety_override_code'] ?? ''));
        $safetyOverrideReason = trim((string) ($payload['safety_override_reason'] ?? ''));
        $entryState = ClinicalOrderLifecycle::normalizeEntryState(
            isset($payload['entry_mode']) ? (string) $payload['entry_mode'] : null,
        );

        $payload['status'] = PharmacyOrderStatus::PENDING->value;
        $payload['order_number'] = $this->generateOrderNumber();
        $payload['tenant_id'] = $this->platformScopeContext->tenantId();
        $payload['facility_id'] = $this->platformScopeContext->facilityId();
        $payload['ordered_by_user_id'] = $payload['ordered_by_user_id'] ?? $actorId;

        if (
            ! array_key_exists('ordered_at', $payload)
            || blank($payload['ordered_at'])
        ) {
            $payload['ordered_at'] = now();
        }

        $payload['quantity_dispensed'] = round((float) ($payload['quantity_dispensed'] ?? 0), 2);
        $payload['quantity_prescribed'] = round((float) ($payload['quantity_prescribed'] ?? 0), 2);
        $this->normalizeStructuredDoseFields($payload);
        $this->applyDispenseUnitDefaults($payload, $selectedCatalogItem);

        $this->applyLifecycleLinkage($payload);
        $payload['clinical_order_session_id'] = $this->resolveClinicalOrderSessionId(
            $payload,
            $actorId,
        );
        if ($entryState === 'draft') {
            if (! array_key_exists('dosage_instruction', $payload) || blank($payload['dosage_instruction'])) {
                $payload['dosage_instruction'] = '';
            }

            $safetyReview = [
                'severity' => 'none',
                'blockers' => [],
                'warnings' => [],
                'suggestedActions' => [],
                'rules' => [],
                'ruleGroups' => [],
                'ruleCodes' => [],
                'ruleCatalogVersion' => MedicationSafetyRuleCatalog::catalogVersion(),
                'overrideCode' => null,
                'overrideOption' => null,
                'overrideSummary' => MedicationSafetyRuleCatalog::buildOverrideSummary([], null, null),
            ];
            ClinicalOrderLifecycle::applyDraftEntryState($payload);
        } else {
            if (blank($payload['clinical_indication'] ?? null)) {
                throw ValidationException::withMessages([
                    'clinicalIndication' => [
                        'Clinical indication is required before this pharmacy order can become active.',
                    ],
                ]);
            }

            $safetyReview = $this->medicationSafetyReviewGate->reviewOrFail(
                patientId: $patientId,
                context: [
                    'approved_medicine_catalog_item_id' => $payload['approved_medicine_catalog_item_id'] ?? null,
                    'medication_code' => $payload['medication_code'] ?? null,
                    'medication_name' => $payload['medication_name'] ?? null,
                    'dosage_instruction' => $payload['dosage_instruction'] ?? null,
                    'dose_quantity' => $payload['dose_quantity'] ?? null,
                    'dose_unit' => $payload['dose_unit'] ?? null,
                    'route' => $payload['route'] ?? null,
                    'frequency' => $payload['frequency'] ?? null,
                    'duration_value' => $payload['duration_value'] ?? null,
                    'duration_unit' => $payload['duration_unit'] ?? null,
                    'clinical_indication' => $payload['clinical_indication'] ?? null,
                    'quantity_prescribed' => $payload['quantity_prescribed'] ?? null,
                    'prescribed_unit' => $payload['prescribed_unit'] ?? null,
                    'dispensed_unit' => $payload['dispensed_unit'] ?? null,
                    'appointment_id' => $payload['appointment_id'] ?? null,
                    'admission_id' => $payload['admission_id'] ?? null,
                    'formulary_decision_status' => $payload['formulary_decision_status'] ?? null,
                ],
                safetyAcknowledged: $safetyAcknowledged,
                safetyOverrideCode: $safetyOverrideCode,
                safetyOverrideReason: $safetyOverrideReason,
            );
            ClinicalOrderLifecycle::applyActiveEntryState($payload, $actorId);
        }

        unset($payload['safety_acknowledged'], $payload['safety_override_code'], $payload['safety_override_reason'], $payload['entry_mode']);

        $createdOrder = $this->pharmacyOrderRepository->create($payload);

        if (! blank($payload['clinical_order_session_id'] ?? null)) {
            $this->orderSessionManager->incrementItemCount((string) $payload['clinical_order_session_id']);
        }

        $this->auditLogRepository->write(
            pharmacyOrderId: $createdOrder['id'],
            action: 'pharmacy-order.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($createdOrder),
            ],
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
                    'override_reason' => $safetyOverrideReason !== '' ? $safetyOverrideReason : null,
                    'override_summary' => $safetyReview['overrideSummary'],
                ],
            ],
        );

        if ($serviceRequestId !== '') {
            $this->serviceRequestLinker->complete(
                serviceRequestId: $serviceRequestId,
                patientId: $patientId,
                serviceType: ServiceRequestServiceType::PHARMACY->value,
                linkedOrderType: ServiceRequestServiceType::PHARMACY->linkedOrderType(),
                linkedOrderId: (string) $createdOrder['id'],
                linkedOrderNumber: isset($createdOrder['order_number']) ? (string) $createdOrder['order_number'] : null,
                actorId: $actorId,
            );
        }

        return $createdOrder;
    }

    private function generateOrderNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'RX'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->pharmacyOrderRepository->existsByOrderNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique pharmacy order number.');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $order): array
    {
        $tracked = [
            'order_number',
            'tenant_id',
            'facility_id',
            'patient_id',
            'admission_id',
            'appointment_id',
            'clinical_order_session_id',
            'replaces_order_id',
            'add_on_to_order_id',
            'ordered_by_user_id',
            'ordered_at',
            'approved_medicine_catalog_item_id',
            'medication_code',
            'medication_name',
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
            'quantity_dispensed',
            'dispensed_unit',
            'dispensing_notes',
            'dispensed_at',
            'formulary_decision_status',
            'formulary_decision_reason',
            'substitution_allowed',
            'substitution_made',
            'substituted_medication_code',
            'substituted_medication_name',
            'substitution_reason',
            'reconciliation_status',
            'reconciliation_note',
            'status',
            'entry_state',
            'signed_at',
            'signed_by_user_id',
            'status_reason',
            'lifecycle_reason_code',
            'entered_in_error_at',
            'entered_in_error_by_user_id',
            'lifecycle_locked_at',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $order[$field] ?? null;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyCatalogManagedApprovedMedicineSelection(array &$payload): array
    {
        $catalogItemId = isset($payload['approved_medicine_catalog_item_id'])
            ? trim((string) ($payload['approved_medicine_catalog_item_id'] ?? ''))
            : '';
        $medicationCode = isset($payload['medication_code'])
            ? trim((string) ($payload['medication_code'] ?? ''))
            : '';

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
        foreach (['dose_unit', 'route', 'frequency', 'duration_unit'] as $field) {
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

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $catalogItem
     */
    private function validateStructuredDoseFields(array $payload, array $catalogItem): void
    {
        $strength = isset($catalogItem['strength']) ? trim((string) $catalogItem['strength']) : '';
        if ($strength === '') {
            return;
        }

        $errors = [];

        $hasDoseQuantity = array_key_exists('dose_quantity', $payload)
            && $payload['dose_quantity'] !== null
            && $payload['dose_quantity'] !== '';
        $hasDoseUnit = array_key_exists('dose_unit', $payload)
            && isset($payload['dose_unit'])
            && $payload['dose_unit'] !== null
            && $payload['dose_unit'] !== '';

        if (! $hasDoseQuantity) {
            $errors['doseQuantity'][] = 'Dose quantity is required when the catalog item has a defined strength.';
        }

        if (! $hasDoseUnit) {
            $errors['doseUnit'][] = 'Dose unit is required when the catalog item has a defined strength.';
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        if (! $hasDoseQuantity || ! $hasDoseUnit) {
            return;
        }

        $dosageForm = mb_strtolower(trim((string) ($catalogItem['dosage_form'] ?? '')));
        if ($dosageForm === 'iv fluid') {
            $doseUnitRaw = mb_strtolower(trim((string) $payload['dose_unit']));
            $canonicalDoseUnit = self::VOLUME_UNIT_SYNONYMS[$doseUnitRaw] ?? null;

            if ($canonicalDoseUnit === null) {
                throw ValidationException::withMessages([
                    'doseUnit' => [
                        "Dose unit \"{$payload['dose_unit']}\" is not a recognized volume unit for an IV fluid. Expected: mL or L.",
                    ],
                ]);
            }

            // Strength here is a concentration (e.g. "0.9%"), not a per-unit dose --
            // the numerator/denominator comparison and expected-dispense-quantity
            // check below don't apply to a fluid bag, so validation stops here.
            return;
        }

        $parsedStrength = $this->parseStrengthString($strength);
        if ($parsedStrength === null) {
            return;
        }

        $doseUnit = mb_strtolower(trim((string) $payload['dose_unit']));

        $numeratorUnit = $parsedStrength['numeratorUnit'] !== null
            ? mb_strtolower($parsedStrength['numeratorUnit'])
            : null;
        $denominatorUnit = $parsedStrength['denominatorUnit'] !== null
            ? mb_strtolower($parsedStrength['denominatorUnit'])
            : null;

        $compatible = ($numeratorUnit !== null && $doseUnit === $numeratorUnit)
            || ($denominatorUnit !== null && $doseUnit === $denominatorUnit);

        if (! $compatible) {
            $compatibleUnits = array_values(array_filter([
                $numeratorUnit,
                $denominatorUnit,
            ]));
            $compatibleList = ! empty($compatibleUnits) ? implode(', ', $compatibleUnits) : $strength;
            throw ValidationException::withMessages([
                'doseUnit' => [
                    "Dose unit \"{$doseUnit}\" is not compatible with the catalog item's strength ({$strength}). Expected: {$compatibleList}.",
                ],
            ]);
        }

        $doseQuantity = (float) $payload['dose_quantity'];
        $expectedQuantity = ($doseQuantity / $parsedStrength['numeratorValue']) * $parsedStrength['denominatorValue'];
        $expectedQuantity = round($expectedQuantity, 4);

        $prescribedQuantity = isset($payload['quantity_prescribed'])
            ? (float) $payload['quantity_prescribed']
            : 0;

        if ($prescribedQuantity > 0 && abs($prescribedQuantity - $expectedQuantity) > 0.01) {
            $expectedUnit = $parsedStrength['denominatorUnit'] ?? $parsedStrength['numeratorUnit'] ?? '';
            throw ValidationException::withMessages([
                'quantityPrescribed' => [
                    "Quantity prescribed ({$prescribedQuantity}) does not match the calculated dispense quantity ({$expectedQuantity} {$expectedUnit}) for the given dose ({$doseQuantity} {$doseUnit}) and strength ({$strength}).",
                ],
            ]);
        }
    }

    /**
     * @return array{numeratorValue: int|float, numeratorUnit: string|null, denominatorValue: int|float, denominatorUnit: string|null}|null
     */
    private function parseStrengthString(string $strength): ?array
    {
        $strength = trim($strength);
        if (preg_match('/^([\d.]+)\s*([a-zA-Z°%]+)(?:\s*\/\s*([\d.]+)\s*([a-zA-Z°%]+))?$/', $strength, $m)) {
            $numValue = is_numeric($m[1]) ? (str_contains($m[1], '.') ? (float) $m[1] : (int) $m[1]) : 0;
            $numUnit = $m[2] !== '' ? $m[2] : null;

            if (isset($m[3], $m[4]) && $m[3] !== '' && $m[4] !== '') {
                $denValue = is_numeric($m[3]) ? (str_contains($m[3], '.') ? (float) $m[3] : (int) $m[3]) : 1;
                $denUnit = $m[4] !== '' ? $m[4] : null;
            } else {
                $denValue = 1;
                $denUnit = null;
            }

            return [
                'numeratorValue' => $numValue,
                'numeratorUnit' => $numUnit,
                'denominatorValue' => $denValue,
                'denominatorUnit' => $denUnit,
            ];
        }

        return null;
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
     * @param array<string, mixed> $payload
     */
    private function resolveClinicalOrderSessionId(array $payload, ?int $actorId): string
    {
        $session = $this->orderSessionManager->ensureSession(
            module: 'pharmacy',
            requestedSessionId: isset($payload['clinical_order_session_id'])
                ? (string) $payload['clinical_order_session_id']
                : null,
            context: [
                'tenant_id' => $payload['tenant_id'] ?? null,
                'facility_id' => $payload['facility_id'] ?? null,
                'patient_id' => $payload['patient_id'] ?? null,
                'appointment_id' => $payload['appointment_id'] ?? null,
                'admission_id' => $payload['admission_id'] ?? null,
                'ordered_by_user_id' => $payload['ordered_by_user_id'] ?? $actorId,
                'submitted_at' => now(),
            ],
        );

        return (string) ($session['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyLifecycleLinkage(array &$payload): void
    {
        $replacesOrderId = trim((string) ($payload['replaces_order_id'] ?? ''));
        $addOnToOrderId = trim((string) ($payload['add_on_to_order_id'] ?? ''));

        ClinicalOrderLifecycle::assertNoConflictingLinkage($replacesOrderId, $addOnToOrderId);

        if ($replacesOrderId !== '') {
            $sourceOrder = $this->pharmacyOrderRepository->findById($replacesOrderId);
            ClinicalOrderLifecycle::assertReplacementSource(
                $sourceOrder,
                $payload,
                'replacesOrderId',
                'pharmacy order',
            );
            $payload['replaces_order_id'] = $replacesOrderId;
        } else {
            $payload['replaces_order_id'] = null;
        }

        if ($addOnToOrderId !== '') {
            $sourceOrder = $this->pharmacyOrderRepository->findById($addOnToOrderId);
            ClinicalOrderLifecycle::assertAddOnSource(
                $sourceOrder,
                $payload,
                'addOnToOrderId',
                'pharmacy order',
            );
            $payload['add_on_to_order_id'] = $addOnToOrderId;
        } else {
            $payload['add_on_to_order_id'] = null;
        }
    }
}
