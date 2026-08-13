<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientAllergyRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientMedicationProfileRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Pharmacy\Application\Support\ApprovedMedicineGovernance;
use App\Modules\Pharmacy\Application\Support\MedicationDoseSafetyKnowledgeBase;
use App\Modules\Pharmacy\Application\Support\MedicationInteractionKnowledgeBase;
use App\Modules\Pharmacy\Application\Support\MedicationLaboratorySafetyKnowledgeBase;
use App\Modules\Pharmacy\Application\Support\MedicationPatientContextResolver;
use App\Modules\Pharmacy\Application\Support\MedicationSafetyRuleCatalog;
use App\Modules\Pharmacy\Application\UseCases\CheckPharmacyOrderDuplicatesUseCase;
use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;
use App\Modules\Pharmacy\Domain\Services\ApprovedMedicineCatalogLookupServiceInterface;

class GetPatientMedicationSafetySummaryUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAllergyRepositoryInterface $patientAllergyRepository,
        private readonly PatientMedicationProfileRepositoryInterface $patientMedicationProfileRepository,
        private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository,
        private readonly LaboratoryOrderRepositoryInterface $laboratoryOrderRepository,
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly CheckPharmacyOrderDuplicatesUseCase $checkPharmacyOrderDuplicatesUseCase,
        private readonly ApprovedMedicineCatalogLookupServiceInterface $approvedMedicineCatalogLookupService,
    ) {}

    public function execute(string $patientId, array $context): ?array
    {
        $patient = $this->patientRepository->findById($patientId);
        if ($patient === null) {
            return null;
        }

        $medicationCode = $this->normalizeText($context['medication_code'] ?? null);
        $medicationName = $this->normalizeText($context['medication_name'] ?? null);
        $dosageInstruction = trim((string) ($context['dosage_instruction'] ?? ''));
        $clinicalIndication = trim((string) ($context['clinical_indication'] ?? ''));
        $quantityPrescribed = $this->normalizeNumeric($context['quantity_prescribed'] ?? null);
        $frequency = $this->normalizeText($context['frequency'] ?? null);
        $doseQuantity = $this->normalizeNumeric($context['dose_quantity'] ?? null);
        $appointmentId = $this->normalizeText($context['appointment_id'] ?? null);
        $admissionId = $this->normalizeText($context['admission_id'] ?? null);
        $approvedMedicineCatalogItemId = $this->normalizeText(
            $context['approved_medicine_catalog_item_id'] ?? null,
        );
        $excludeOrderId = $this->normalizeText($context['exclude_order_id'] ?? null);
        $appointment = $appointmentId !== ''
            ? $this->appointmentRepository->findById($appointmentId)
            : null;
        $patientContext = MedicationPatientContextResolver::resolve($patient, $appointment);

        $activeAllergies = $this->patientAllergyRepository->listActiveByPatientId($patientId);
        $catalogItem = $this->resolveApprovedMedicineCatalogItem(
            $approvedMedicineCatalogItemId,
            $medicationCode,
        );
        $allergyConflicts = array_values(array_filter(
            $activeAllergies,
            fn (array $allergy): bool => $this->allergyMatchesMedication(
                $allergy,
                $medicationCode,
                $medicationName,
            ),
        ));

        $activeMedicationProfile = $this->patientMedicationProfileRepository->listActiveByPatientId($patientId);
        $activeMedicationOrders = $this->pharmacyOrderRepository->activeMedicationOrdersForPatient(
            patientId: $patientId,
            excludeOrderId: $excludeOrderId !== '' ? $excludeOrderId : null,
            limit: 25,
        );
        $activeProfileMatches = $this->patientMedicationProfileRepository->findMatchingActiveByPatientId(
            patientId: $patientId,
            medicationCode: $medicationCode,
            medicationName: $medicationName,
            limit: 10,
        );

        $matchingActiveOrders = $this->pharmacyOrderRepository->matchingActiveMedicationOrders(
            patientId: $patientId,
            medicationCode: $medicationCode,
            medicationName: $medicationName,
            excludeOrderId: $excludeOrderId !== '' ? $excludeOrderId : null,
            limit: 10,
        );

        $recentPatientDuplicates = [];
        $sameEncounterDuplicates = [];
        if ($medicationCode !== '' || $approvedMedicineCatalogItemId !== '') {
            $duplicates = $this->checkPharmacyOrderDuplicatesUseCase->execute([
                'patient_id' => $patientId,
                'appointment_id' => $appointmentId,
                'admission_id' => $admissionId,
                'approved_medicine_catalog_item_id' => $approvedMedicineCatalogItemId,
                'medication_code' => $medicationCode,
                'exclude_order_id' => $excludeOrderId !== '' ? $excludeOrderId : null,
            ]);
            $sameEncounterDuplicates = $duplicates['sameEncounterDuplicates'] ?? [];
            $recentPatientDuplicates = $duplicates['recentPatientDuplicates'] ?? [];
        }

        $unreconciledDispensedOrders = $this->pharmacyOrderRepository->unreconciledReleasedOrdersForPatient(
            patientId: $patientId,
            limit: 10,
        );
        $recentLaboratoryResults = $this->laboratoryOrderRepository->recentVerifiedResultsForPatient(
            patientId: $patientId,
            limit: 10,
        );
        $interactionConflicts = MedicationInteractionKnowledgeBase::detectConflicts(
            targetMedicationCode: $medicationCode !== '' ? $medicationCode : null,
            targetMedicationName: $medicationName !== '' ? $medicationName : null,
            contextEntries: $this->buildMedicationInteractionContext(
                activeMedicationProfile: $activeMedicationProfile,
                activeMedicationOrders: $activeMedicationOrders,
            ),
        );
        $policyRecommendation = ApprovedMedicineGovernance::policyRecommendation(
            catalogItem: $catalogItem,
            clinicalIndication: $clinicalIndication,
            order: [
                'formulary_decision_status' => $context['formulary_decision_status'] ?? null,
                'substitution_allowed' => $context['substitution_allowed'] ?? null,
                'substitution_made' => $context['substitution_made'] ?? null,
            ],
        );
        $laboratorySignals = MedicationLaboratorySafetyKnowledgeBase::detectSignals(
            targetMedicationCode: $medicationCode !== '' ? $medicationCode : null,
            targetMedicationName: $medicationName !== '' ? $medicationName : null,
            laboratoryResults: $recentLaboratoryResults,
        );

        $rules = [];

        if ($allergyConflicts !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'allergy_match',
                severity: 'critical',
                category: 'allergy',
                message: 'Active allergy or intolerance matches the selected medicine.',
                suggestedAction: 'Review allergy history, confirm reaction severity, and document whether the order should continue.',
                source: [
                    'referenceLabel' => (string) ($allergyConflicts[0]['substance_name'] ?? ''),
                ],
            );
        }

        $hasMedicationTarget = $medicationCode !== ''
            || $medicationName !== ''
            || $approvedMedicineCatalogItemId !== '';

        if ($hasMedicationTarget && $clinicalIndication === '') {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'missing_clinical_indication',
                severity: 'warning',
                category: 'indication',
                message: 'Clinical indication is not documented for this medicine.',
                suggestedAction: 'Document why the patient needs this medicine before signing or releasing the order.',
                source: [
                    'referenceLabel' => $medicationName !== '' ? $medicationName : $medicationCode,
                ],
            );
        }

        if (ApprovedMedicineGovernance::requiresPolicyReview($catalogItem)) {
            $suggestedAction = ApprovedMedicineGovernance::preferredAlternativesSummary(
                $catalogItem,
                $clinicalIndication,
            );
            $restrictionNote = ApprovedMedicineGovernance::profile($catalogItem)['restrictionNote'];

            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'catalog_policy_review_required',
                severity: 'warning',
                category: 'policy',
                message: $restrictionNote !== null
                    ? 'This medicine is governed by approved-medicines policy and needs pharmacy review before preparation or release. '.$restrictionNote
                    : 'This medicine is governed by approved-medicines policy and needs pharmacy review before preparation or release.',
                suggestedAction: $suggestedAction !== null
                    ? 'Review restricted-use policy and consider preferred alternatives: '.$suggestedAction.'.'
                    : 'Review the restricted-use policy note before finalizing downstream pharmacy workflow.',
                source: [
                    'referenceId' => (string) ($catalogItem['id'] ?? ''),
                    'referenceLabel' => (string) ($catalogItem['display_name'] ?? $catalogItem['name'] ?? ''),
                ],
            );
        }

        if (ApprovedMedicineGovernance::indicationNeedsClarification($catalogItem, $clinicalIndication)) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'catalog_restriction_indication_mismatch',
                severity: 'warning',
                category: 'policy',
                message: 'The documented clinical indication does not clearly match the restricted-use guidance for this medicine.',
                suggestedAction: 'Clarify the indication or review whether a more suitable formulary medicine should be used.',
                source: [
                    'referenceId' => (string) ($catalogItem['id'] ?? ''),
                    'referenceLabel' => (string) ($catalogItem['display_name'] ?? $catalogItem['name'] ?? ''),
                ],
            );
        }

        foreach (MedicationDoseSafetyKnowledgeBase::detectRules(
            targetMedicationCode: $medicationCode !== '' ? $medicationCode : null,
            targetMedicationName: $medicationName !== '' ? $medicationName : null,
            dosageInstruction: $dosageInstruction,
            quantityPrescribed: $quantityPrescribed,
            clinicalIndication: $clinicalIndication,
            patientAgeYears: $patientContext['age_years'],
            patientAgeMonths: $patientContext['age_months'],
            patientWeightKg: $patientContext['weight_kg'],
            frequency: $frequency,
            doseQuantity: $doseQuantity,
        ) as $doseRule) {
            $rules[] = $doseRule;
        }

        if ($activeProfileMatches !== [] || $matchingActiveOrders !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'active_therapy_duplicate',
                severity: 'warning',
                category: 'duplicate_therapy',
                message: 'This medicine already appears in the current medication list or active pharmacy workflow.',
                suggestedAction: 'Confirm whether this is a continuation, replacement, or duplicate therapy before signing.',
                source: [
                    'type' => $activeProfileMatches !== [] ? 'current_medication_list' : 'active_pharmacy_workflow',
                    'label' => $activeProfileMatches !== [] ? 'Current medication list' : 'Active pharmacy workflow',
                    'referenceLabel' => (string) (($activeProfileMatches[0]['medication_name'] ?? null) ?: ($matchingActiveOrders[0]['medication_name'] ?? null) ?: ''),
                ],
            );
        }

        if ($sameEncounterDuplicates !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'same_encounter_duplicate',
                severity: 'warning',
                category: 'duplicate_therapy',
                message: 'An active order for the same medicine already exists in this encounter.',
                suggestedAction: 'Review the current encounter orders before adding another medication order.',
                source: [
                    'type' => 'encounter_medication_orders',
                    'label' => 'Current encounter orders',
                    'referenceId' => (string) ($sameEncounterDuplicates[0]['id'] ?? ''),
                    'referenceLabel' => (string) ($sameEncounterDuplicates[0]['order_number'] ?? ''),
                ],
            );
        }

        if ($recentPatientDuplicates !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'recent_duplicate_history',
                severity: 'warning',
                category: 'history',
                message: 'Recent medication orders for the same medicine exist in the last 30 days.',
                source: [
                    'type' => 'recent_medication_history',
                    'label' => 'Recent medication history',
                    'referenceId' => (string) ($recentPatientDuplicates[0]['id'] ?? ''),
                    'referenceLabel' => (string) ($recentPatientDuplicates[0]['order_number'] ?? ''),
                ],
            );
        }

        if ($unreconciledDispensedOrders !== []) {
            $rules[] = MedicationSafetyRuleCatalog::makeRule(
                code: 'unreconciled_released_medications',
                severity: 'warning',
                category: 'reconciliation',
                message: 'Previously released medication orders are still waiting for reconciliation follow-up.',
                suggestedAction: 'Complete medication reconciliation before releasing more ongoing therapy.',
                source: [
                    'referenceId' => (string) ($unreconciledDispensedOrders[0]['id'] ?? ''),
                    'referenceLabel' => (string) ($unreconciledDispensedOrders[0]['order_number'] ?? ''),
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
            'activeMedicationProfile' => $activeMedicationProfile,
            'matchingActiveOrders' => $matchingActiveOrders,
            'sameEncounterDuplicates' => $sameEncounterDuplicates,
            'recentPatientDuplicates' => $recentPatientDuplicates,
            'unreconciledDispensedOrders' => $unreconciledDispensedOrders,
            'suggestedActions' => $ruleSummary['suggestedActions'],
        ];
    }

    private function allergyMatchesMedication(
        array $allergy,
        string $medicationCode,
        string $medicationName
    ): bool {
        $allergyCode = $this->normalizeText($allergy['substance_code'] ?? null);
        $allergyName = $this->normalizeText($allergy['substance_name'] ?? null);

        if ($allergyCode !== '' && $medicationCode !== '' && $allergyCode === $medicationCode) {
            return true;
        }

        if ($allergyName === '' || $medicationName === '') {
            return false;
        }

        return str_contains($medicationName, $allergyName)
            || str_contains($allergyName, $medicationName);
    }

    private function normalizeText(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function normalizeNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function resolveApprovedMedicineCatalogItem(
        string $approvedMedicineCatalogItemId,
        string $medicationCode
    ): ?array {
        if ($approvedMedicineCatalogItemId !== '') {
            return $this->approvedMedicineCatalogLookupService->findActiveById($approvedMedicineCatalogItemId);
        }

        if ($medicationCode !== '') {
            return $this->approvedMedicineCatalogLookupService->findActiveByCode($medicationCode);
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
        $normalizedCode = $this->normalizeText($medicationCode);
        if ($normalizedCode !== '') {
            return 'code:'.$normalizedCode;
        }

        $normalizedName = $this->normalizeText($medicationName);

        return $normalizedName !== '' ? 'name:'.$normalizedName : '';
    }
}
