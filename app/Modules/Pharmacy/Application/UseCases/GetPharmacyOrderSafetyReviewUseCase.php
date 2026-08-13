<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientAllergyRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientMedicationProfileRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Pharmacy\Application\Support\ApprovedMedicineGovernance;
use App\Modules\Pharmacy\Application\Support\MedicationDoseSafetyKnowledgeBase;
use App\Modules\Pharmacy\Application\Support\MedicationInteractionKnowledgeBase;
use App\Modules\Pharmacy\Application\Support\MedicationLaboratorySafetyKnowledgeBase;
use App\Modules\Pharmacy\Application\Support\MedicationPatientContextResolver;
use App\Modules\Pharmacy\Application\Support\MedicationSafetyRuleCatalog;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Domain\Services\ApprovedMedicineCatalogLookupServiceInterface;

class GetPharmacyOrderSafetyReviewUseCase
{
    public function __construct(
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly CheckPharmacyOrderDuplicatesUseCase $checkPharmacyOrderDuplicatesUseCase,
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly PatientAllergyRepositoryInterface $patientAllergyRepository,
        private readonly PatientMedicationProfileRepositoryInterface $patientMedicationProfileRepository,
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly LaboratoryOrderRepositoryInterface $laboratoryOrderRepository,
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly ApprovedMedicineCatalogLookupServiceInterface $approvedMedicineCatalogLookupService,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
    ) {}

    /**
     * @return array{
     *     severity: 'none'|'warning'|'critical',
     *     blockers: array<int, string>,
     *     warnings: array<int, string>,
     *     rules: array<int, array<string, mixed>>,
     *     overrideOptions: array<int, array<string, string>>,
     *     patientContext: array{
     *         ageYears:int|null,
     *         ageMonths:int|null,
     *         weightKg:float|null,
     *         weightSource:string|null,
     *         isPediatric:bool
     *     },
     *     allergyConflicts: array<int, array<string, mixed>>,
     *     interactionConflicts: array<int, array<string, mixed>>,
     *     laboratorySignals: array<int, array<string, mixed>>,
     *     activeProfileMatches: array<int, array<string, mixed>>,
     *     matchingActiveOrders: array<int, array<string, mixed>>,
     *     sameEncounterDuplicates: array<int, array<string, mixed>>,
     *     recentPatientDuplicates: array<int, array<string, mixed>>,
     *     recentMedicationHistory: array<int, array<string, mixed>>,
     *     unreconciledReleasedOrders: array<int, array<string, mixed>>,
     *     dispenseInventory: array<string, mixed>|null
     * }|null
     */
    public function execute(string $id): ?array
    {
        $order = $this->pharmacyOrderRepository->findById($id);
        if ($order === null) {
            return null;
        }

        $patient = $this->patientRepository->findById((string) ($order['patient_id'] ?? ''));
        $appointmentId = trim((string) ($order['appointment_id'] ?? ''));
        $appointment = $appointmentId !== ''
            ? $this->appointmentRepository->findById($appointmentId)
            : null;
        $patientContext = MedicationPatientContextResolver::resolve($patient, $appointment);

        $duplicates = $this->checkPharmacyOrderDuplicatesUseCase->execute([
            'patient_id' => $order['patient_id'] ?? null,
            'appointment_id' => $order['appointment_id'] ?? null,
            'admission_id' => $order['admission_id'] ?? null,
            'approved_medicine_catalog_item_id' => $order['approved_medicine_catalog_item_id'] ?? null,
            'medication_code' => $order['medication_code'] ?? null,
            'exclude_order_id' => $id,
        ]);

        $recentMedicationHistory = $this->pharmacyOrderRepository->recentActiveMedicationHistory(
            patientId: (string) ($order['patient_id'] ?? ''),
            excludeOrderId: $id,
            limit: 5,
        );

        $catalogItem = $this->resolveApprovedMedicineCatalogItem(
            (string) ($order['approved_medicine_catalog_item_id'] ?? ''),
            (string) ($order['medication_code'] ?? ''),
        );

        $allergyConflicts = array_values(array_filter(
            $this->patientAllergyRepository->listActiveByPatientId((string) ($order['patient_id'] ?? '')),
            fn (array $allergy): bool => $this->allergyMatchesMedication(
                $allergy,
                $this->dispenseTargetMedicationCode($order),
                $this->dispenseTargetMedicationName($order),
            ),
        ));
        $activeMedicationProfile = $this->patientMedicationProfileRepository->listActiveByPatientId(
            (string) ($order['patient_id'] ?? ''),
        );

        $activeProfileMatches = $this->patientMedicationProfileRepository->findMatchingActiveByPatientId(
            patientId: (string) ($order['patient_id'] ?? ''),
            medicationCode: $this->dispenseTargetMedicationCode($order),
            medicationName: $this->dispenseTargetMedicationName($order),
            limit: 5,
        );
        $activeMedicationOrders = $this->pharmacyOrderRepository->activeMedicationOrdersForPatient(
            patientId: (string) ($order['patient_id'] ?? ''),
            excludeOrderId: $id,
            limit: 25,
        );

        $matchingActiveOrders = $this->pharmacyOrderRepository->matchingActiveMedicationOrders(
            patientId: (string) ($order['patient_id'] ?? ''),
            medicationCode: $this->dispenseTargetMedicationCode($order),
            medicationName: $this->dispenseTargetMedicationName($order),
            excludeOrderId: $id,
            limit: 5,
        );
        $interactionConflicts = MedicationInteractionKnowledgeBase::detectConflicts(
            targetMedicationCode: $this->dispenseTargetMedicationCode($order),
            targetMedicationName: $this->dispenseTargetMedicationName($order),
            contextEntries: $this->buildMedicationInteractionContext(
                activeMedicationProfile: $activeMedicationProfile,
                activeMedicationOrders: $activeMedicationOrders,
            ),
        );
        $laboratorySignals = MedicationLaboratorySafetyKnowledgeBase::detectSignals(
            targetMedicationCode: $this->dispenseTargetMedicationCode($order),
            targetMedicationName: $this->dispenseTargetMedicationName($order),
            laboratoryResults: $this->laboratoryOrderRepository->recentVerifiedResultsForPatient(
                patientId: (string) ($order['patient_id'] ?? ''),
                limit: 10,
            ),
        );
        $policyRecommendation = ApprovedMedicineGovernance::policyRecommendation(
            catalogItem: $catalogItem,
            clinicalIndication: (string) ($order['clinical_indication'] ?? ''),
            order: $order,
        );

        $unreconciledReleasedOrders = $this->pharmacyOrderRepository->unreconciledReleasedOrders(
            patientId: (string) ($order['patient_id'] ?? ''),
            excludeOrderId: $id,
            limit: 5,
        );

        $dispenseInventory = $this->inventoryItemRepository->findBestActiveMatchByCodeOrName(
            $this->dispenseTargetMedicationCode($order),
            $this->dispenseTargetMedicationName($order),
        );
        if ($dispenseInventory !== null) {
            $dispenseInventory = $this->inventoryBatchStockService->enrichItemAvailability($dispenseInventory);
        }

        $rules = [];

        if ($this->needsBlockingPolicyReview($order)) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'policy_review_required',
                severity: 'critical',
                category: 'policy',
                message: 'Policy review is still required before this medication can move to pharmacist release.',
                source: [
                    'referenceId' => (string) ($catalogItem['id'] ?? ''),
                    'referenceLabel' => (string) ($catalogItem['display_name'] ?? $catalogItem['name'] ?? ''),
                ],
            );
        }

        if (ApprovedMedicineGovernance::requiresPolicyReview($catalogItem)) {
            $restrictionNote = ApprovedMedicineGovernance::profile($catalogItem)['restrictionNote'];
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'catalog_policy_review_required',
                severity: 'warning',
                category: 'policy',
                message: $restrictionNote !== null
                    ? 'This medicine carries restricted-use governance. '.$restrictionNote
                    : 'This medicine carries restricted-use governance and should stay under explicit policy review.',
                suggestedAction: 'Capture a clear approved-medicines decision before release continues.',
                source: [
                    'referenceId' => (string) ($catalogItem['id'] ?? ''),
                    'referenceLabel' => (string) ($catalogItem['display_name'] ?? $catalogItem['name'] ?? ''),
                ],
            );
        }

        if (
            ApprovedMedicineGovernance::indicationNeedsClarification(
                $catalogItem,
                (string) ($order['clinical_indication'] ?? ''),
            )
        ) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'catalog_restriction_indication_mismatch',
                severity: 'warning',
                category: 'policy',
                message: 'The documented clinical indication does not clearly match the restricted-use guidance for this medicine.',
                suggestedAction: 'Confirm the indication during policy review before release progresses.',
                source: [
                    'referenceId' => (string) ($catalogItem['id'] ?? ''),
                    'referenceLabel' => (string) ($catalogItem['display_name'] ?? $catalogItem['name'] ?? ''),
                ],
            );
        }

        if ($dispenseInventory === null) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'inventory_match_missing',
                severity: 'critical',
                category: 'inventory',
                message: 'No active inventory match is linked to the medicine that will be dispensed.',
                source: [
                    'referenceLabel' => $this->dispenseTargetMedicationName($order),
                ],
            );
        } else {
            $currentStock = round((float) ($dispenseInventory['available_stock'] ?? $dispenseInventory['current_stock'] ?? 0), 2);
            $reorderLevel = round((float) ($dispenseInventory['reorder_level'] ?? 0), 2);

            if ($currentStock <= 0) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'inventory_out_of_stock',
                    severity: 'critical',
                    category: 'inventory',
                    message: ($dispenseInventory['batch_tracking_mode'] ?? 'untracked') === 'tracked'
                        ? 'No valid FEFO batch stock is currently available for the medicine selected for release.'
                        : 'Dispense stock is currently zero for the medicine selected for release.',
                    source: [
                        'referenceId' => (string) ($dispenseInventory['id'] ?? ''),
                        'referenceLabel' => (string) ($dispenseInventory['name'] ?? $dispenseInventory['item_name'] ?? ''),
                    ],
                );
            } elseif ($currentStock <= $reorderLevel) {
                $rules[] = MedicationSafetyRuleCatalog::makeRule(
                    code: 'inventory_low_stock',
                    severity: 'warning',
                    category: 'inventory',
                    message: 'Dispense stock is at or below reorder level and should be released carefully.',
                    source: [
                        'referenceId' => (string) ($dispenseInventory['id'] ?? ''),
                        'referenceLabel' => (string) ($dispenseInventory['name'] ?? $dispenseInventory['item_name'] ?? ''),
                    ],
                );
            }
        }

        if (($duplicates['sameEncounterDuplicates'] ?? []) !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'same_encounter_duplicate',
                severity: 'warning',
                category: 'duplicate_therapy',
                message: 'Another active order for the same medicine is already open in this encounter.',
                source: [
                    'type' => 'encounter_medication_orders',
                    'label' => 'Current encounter orders',
                    'referenceId' => (string) (($duplicates['sameEncounterDuplicates'][0]['id'] ?? '')),
                    'referenceLabel' => (string) (($duplicates['sameEncounterDuplicates'][0]['order_number'] ?? '')),
                ],
            );
        }

        if (($duplicates['recentPatientDuplicates'] ?? []) !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'recent_duplicate_history',
                severity: 'warning',
                category: 'history',
                message: 'The patient has recent orders for the same medicine within the last 30 days.',
                source: [
                    'type' => 'recent_medication_history',
                    'label' => 'Recent medication history',
                    'referenceId' => (string) (($duplicates['recentPatientDuplicates'][0]['id'] ?? '')),
                    'referenceLabel' => (string) (($duplicates['recentPatientDuplicates'][0]['order_number'] ?? '')),
                ],
            );
        }

        if ($allergyConflicts !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'allergy_match',
                severity: 'critical',
                category: 'allergy',
                message: 'Active allergy or intolerance matches the medicine selected for release.',
                source: [
                    'referenceLabel' => (string) ($allergyConflicts[0]['substance_name'] ?? ''),
                ],
            );
        }

        if (blank($order['clinical_indication'] ?? null)) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'missing_clinical_indication',
                severity: 'warning',
                category: 'indication',
                message: 'Clinical indication is not documented for this medicine.',
                suggestedAction: 'Document why the patient needs this medicine before signing or releasing the order.',
                source: [
                    'referenceLabel' => $this->dispenseTargetMedicationName($order),
                ],
            );
        }

        foreach (MedicationDoseSafetyKnowledgeBase::detectRules(
            targetMedicationCode: $this->dispenseTargetMedicationCode($order),
            targetMedicationName: $this->dispenseTargetMedicationName($order),
            dosageInstruction: (string) ($order['dosage_instruction'] ?? ''),
            quantityPrescribed: $order['quantity_prescribed'] ?? null,
            clinicalIndication: (string) ($order['clinical_indication'] ?? ''),
            patientAgeYears: $patientContext['age_years'],
            patientAgeMonths: $patientContext['age_months'],
            patientWeightKg: $patientContext['weight_kg'],
        ) as $doseRule) {
            $rules[] = $doseRule;
        }

        if ($activeProfileMatches !== [] || $matchingActiveOrders !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'active_therapy_duplicate',
                severity: 'warning',
                category: 'duplicate_therapy',
                message: 'This medicine is already present in the current medication list or pharmacy workflow.',
                source: [
                    'type' => $activeProfileMatches !== [] ? 'current_medication_list' : 'active_pharmacy_workflow',
                    'label' => $activeProfileMatches !== [] ? 'Current medication list' : 'Active pharmacy workflow',
                    'referenceLabel' => (string) (($activeProfileMatches[0]['medication_name'] ?? null) ?: ($matchingActiveOrders[0]['medication_name'] ?? null) ?: ''),
                ],
            );
        }

        if ($unreconciledReleasedOrders !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'unreconciled_released_medications',
                severity: 'warning',
                category: 'reconciliation',
                message: 'The patient still has previously dispensed medication orders awaiting reconciliation follow-up.',
                source: [
                    'referenceId' => (string) ($unreconciledReleasedOrders[0]['id'] ?? ''),
                    'referenceLabel' => (string) ($unreconciledReleasedOrders[0]['order_number'] ?? ''),
                ],
            );
        }

        foreach ($interactionConflicts as $interactionConflict) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: (string) ($interactionConflict['rule_code'] ?? 'interaction'),
                severity: (string) ($interactionConflict['severity'] ?? 'warning'),
                category: 'interaction',
                message: (string) ($interactionConflict['message'] ?? 'Medication interaction needs review.'),
                suggestedAction: (string) ($interactionConflict['recommended_action'] ?? ''),
                source: [
                    'type' => (string) ($interactionConflict['source_type'] ?? ''),
                    'label' => (string) ($interactionConflict['source_label'] ?? ''),
                    'referenceId' => (string) ($interactionConflict['interacting_medication_code'] ?? ''),
                    'referenceLabel' => (string) ($interactionConflict['interacting_medication_name'] ?? ''),
                ],
            );
        }

        foreach ($laboratorySignals as $laboratorySignal) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: (string) ($laboratorySignal['rule_code'] ?? 'laboratory_signal'),
                severity: (string) ($laboratorySignal['severity'] ?? 'warning'),
                category: 'laboratory',
                message: (string) ($laboratorySignal['message'] ?? 'Recent laboratory result needs review.'),
                suggestedAction: (string) ($laboratorySignal['recommended_action'] ?? ''),
                source: [
                    'type' => 'laboratory_result',
                    'label' => (string) ($laboratorySignal['source_test_name'] ?? 'Recent verified laboratory result'),
                    'referenceId' => (string) ($laboratorySignal['source_order_id'] ?? ''),
                    'referenceLabel' => (string) ($laboratorySignal['source_result_summary'] ?? ''),
                    'observedAt' => (string) ($laboratorySignal['source_verified_at'] ?? ''),
                    'flag' => (string) ($laboratorySignal['source_flag'] ?? ''),
                ],
            );
        }

        $ruleSummary = MedicationSafetyRuleCatalog::summarizeRules($rules);
        $ruleGroups = MedicationSafetyRuleCatalog::groupRules($rules);

        return [
            'severity' => $ruleSummary['severity'],
            'blockers' => $ruleSummary['blockers'],
            'warnings' => $ruleSummary['warnings'],
            'rules' => $rules,
            'ruleGroups' => $ruleGroups,
            'ruleSummary' => $ruleSummary,
            'ruleCatalogVersion' => MedicationSafetyRuleCatalog::catalogVersion(),
            'overrideOptions' => MedicationSafetyRuleCatalog::overrideOptions(),
            'patientContext' => [
                'ageYears' => $patientContext['age_years'],
                'ageMonths' => $patientContext['age_months'],
                'weightKg' => $patientContext['weight_kg'],
                'weightSource' => $patientContext['weight_source'],
                'isPediatric' => $patientContext['age_years'] !== null
                    && $patientContext['age_years'] < 18,
            ],
            'allergyConflicts' => $allergyConflicts,
            'interactionConflicts' => $interactionConflicts,
            'laboratorySignals' => $laboratorySignals,
            'policyRecommendation' => $policyRecommendation,
            'activeProfileMatches' => $activeProfileMatches,
            'matchingActiveOrders' => $matchingActiveOrders,
            'sameEncounterDuplicates' => $duplicates['sameEncounterDuplicates'] ?? [],
            'recentPatientDuplicates' => $duplicates['recentPatientDuplicates'] ?? [],
            'recentMedicationHistory' => $recentMedicationHistory,
            'unreconciledReleasedOrders' => $unreconciledReleasedOrders,
            'dispenseInventory' => $dispenseInventory,
            'suggestedActions' => $ruleSummary['suggestedActions'],
        ];
    }

    /**
     * @param array<string, mixed> $order
     */
    private function needsBlockingPolicyReview(array $order): bool
    {
        $status = strtolower(trim((string) ($order['status'] ?? '')));
        $formularyDecision = strtolower(trim((string) ($order['formulary_decision_status'] ?? 'not_reviewed')));
        $substitutionMade = (bool) ($order['substitution_made'] ?? false);

        if ($status === 'dispensed' || $status === 'cancelled') {
            return false;
        }

        if ($formularyDecision === 'non_formulary' && $substitutionMade) {
            return false;
        }

        return $formularyDecision === 'non_formulary'
            || $formularyDecision === 'not_reviewed'
            || $formularyDecision === 'restricted';
    }

    /**
     * @param array<string, mixed> $order
     */
    private function dispenseTargetMedicationCode(array $order): ?string
    {
        if ((bool) ($order['substitution_made'] ?? false)) {
            $code = trim((string) ($order['substituted_medication_code'] ?? ''));
            if ($code !== '') {
                return $code;
            }
        }

        $code = trim((string) ($order['medication_code'] ?? ''));

        return $code !== '' ? $code : null;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function dispenseTargetMedicationName(array $order): ?string
    {
        if ((bool) ($order['substitution_made'] ?? false)) {
            $name = trim((string) ($order['substituted_medication_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        $name = trim((string) ($order['medication_name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    private function allergyMatchesMedication(
        array $allergy,
        ?string $medicationCode,
        ?string $medicationName
    ): bool {
        $normalizedAllergyCode = mb_strtolower(trim((string) ($allergy['substance_code'] ?? '')));
        $normalizedAllergyName = mb_strtolower(trim((string) ($allergy['substance_name'] ?? '')));
        $normalizedMedicationCode = mb_strtolower(trim((string) ($medicationCode ?? '')));
        $normalizedMedicationName = mb_strtolower(trim((string) ($medicationName ?? '')));

        if (
            $normalizedAllergyCode !== ''
            && $normalizedMedicationCode !== ''
            && $normalizedAllergyCode === $normalizedMedicationCode
        ) {
            return true;
        }

        if ($normalizedAllergyName === '' || $normalizedMedicationName === '') {
            return false;
        }

        return str_contains($normalizedMedicationName, $normalizedAllergyName)
            || str_contains($normalizedAllergyName, $normalizedMedicationName);
    }

    private function resolveApprovedMedicineCatalogItem(string $catalogItemId, string $medicationCode): ?array
    {
        $normalizedCatalogItemId = trim($catalogItemId);
        if ($normalizedCatalogItemId !== '') {
            return $this->approvedMedicineCatalogLookupService->findActiveById($normalizedCatalogItemId);
        }

        $normalizedMedicationCode = trim($medicationCode);
        if ($normalizedMedicationCode !== '') {
            return $this->approvedMedicineCatalogLookupService->findActiveByCode($normalizedMedicationCode);
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $activeMedicationProfile
     * @param array<int, array<string, mixed>> $activeMedicationOrders
     * @return array<int, array<string, mixed>>
     */
    private function buildMedicationInteractionContext(
        array $activeMedicationProfile,
        array $activeMedicationOrders
    ): array {
        $context = [];
        $coveredMedicationKeys = [];

        foreach ($activeMedicationProfile as $profile) {
            $medicationKey = $this->medicationContextKey(
                $profile['medication_code'] ?? null,
                $profile['medication_name'] ?? null,
            );

            if ($medicationKey !== '') {
                $coveredMedicationKeys[$medicationKey] = true;
            }

            $context[] = [
                'medication_code' => $profile['medication_code'] ?? null,
                'medication_name' => $profile['medication_name'] ?? null,
                'source_type' => 'current_medication_list',
                'source_label' => 'current medication list',
            ];
        }

        foreach ($activeMedicationOrders as $order) {
            $medicationKey = $this->medicationContextKey(
                $order['medication_code'] ?? null,
                $order['medication_name'] ?? null,
            );

            if ($medicationKey !== '' && isset($coveredMedicationKeys[$medicationKey])) {
                continue;
            }

            $context[] = [
                'medication_code' => $order['medication_code'] ?? null,
                'medication_name' => $order['medication_name'] ?? null,
                'source_type' => 'active_order',
                'source_label' => 'active pharmacy workflow',
            ];
        }

        return $context;
    }

    private function medicationContextKey(mixed $medicationCode, mixed $medicationName): string
    {
        $normalizedCode = mb_strtolower(trim((string) $medicationCode));
        if ($normalizedCode !== '') {
            return 'code:'.$normalizedCode;
        }

        $normalizedName = mb_strtolower(trim((string) $medicationName));

        return $normalizedName !== '' ? 'name:'.$normalizedName : '';
    }
}
