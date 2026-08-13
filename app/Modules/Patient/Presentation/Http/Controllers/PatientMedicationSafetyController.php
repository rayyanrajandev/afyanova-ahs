<?php

namespace App\Modules\Patient\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Patient\Application\UseCases\CreatePatientAllergyUseCase;
use App\Modules\Patient\Application\UseCases\CreatePatientMedicationProfileUseCase;
use App\Modules\Patient\Application\UseCases\GetPatientMedicationReconciliationUseCase;
use App\Modules\Patient\Application\UseCases\GetPatientMedicationSafetySummaryUseCase;
use App\Modules\Patient\Application\UseCases\ListPatientAllergiesUseCase;
use App\Modules\Patient\Application\UseCases\ListPatientMedicationProfilesUseCase;
use App\Modules\Patient\Application\UseCases\UpdatePatientAllergyUseCase;
use App\Modules\Patient\Application\UseCases\UpdatePatientMedicationProfileUseCase;
use App\Modules\Patient\Presentation\Http\Requests\StorePatientAllergyRequest;
use App\Modules\Patient\Presentation\Http\Requests\StorePatientMedicationProfileRequest;
use App\Modules\Patient\Presentation\Http\Requests\UpdatePatientAllergyRequest;
use App\Modules\Patient\Presentation\Http\Requests\UpdatePatientMedicationProfileRequest;
use App\Modules\Patient\Presentation\Http\Transformers\PatientAllergyResponseTransformer;
use App\Modules\Patient\Presentation\Http\Transformers\PatientMedicationProfileResponseTransformer;
use App\Modules\Pharmacy\Presentation\Http\Transformers\MedicationInteractionConflictResponseTransformer;
use App\Modules\Pharmacy\Presentation\Http\Transformers\MedicationLaboratorySignalResponseTransformer;
use App\Modules\Pharmacy\Presentation\Http\Transformers\PharmacyOrderResponseTransformer;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientMedicationSafetyController extends Controller
{
    public function allergies(
        string $id,
        Request $request,
        ListPatientAllergiesUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Patient not found.');

        return response()->json([
            'data' => array_map([PatientAllergyResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function storeAllergy(
        string $id,
        StorePatientAllergyRequest $request,
        CreatePatientAllergyUseCase $useCase
    ): JsonResponse {
        try {
            $record = $useCase->execute(
                patientId: $id,
                payload: $this->toAllergyPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($record === null, 404, 'Patient not found.');

        return response()->json([
            'data' => PatientAllergyResponseTransformer::transform($record),
        ], 201);
    }

    public function updateAllergy(
        string $id,
        string $allergyId,
        UpdatePatientAllergyRequest $request,
        UpdatePatientAllergyUseCase $useCase
    ): JsonResponse {
        try {
            $record = $useCase->execute(
                patientId: $id,
                allergyId: $allergyId,
                payload: $this->toAllergyPersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($record === null, 404, 'Patient allergy not found.');

        return response()->json([
            'data' => PatientAllergyResponseTransformer::transform($record),
        ]);
    }

    public function medicationProfile(
        string $id,
        Request $request,
        ListPatientMedicationProfilesUseCase $useCase
    ): JsonResponse {
        $result = $useCase->execute($id, $request->all());
        abort_if($result === null, 404, 'Patient not found.');

        return response()->json([
            'data' => array_map([PatientMedicationProfileResponseTransformer::class, 'transform'], $result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function storeMedicationProfile(
        string $id,
        StorePatientMedicationProfileRequest $request,
        CreatePatientMedicationProfileUseCase $useCase
    ): JsonResponse {
        try {
            $record = $useCase->execute(
                patientId: $id,
                payload: $this->toMedicationProfilePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($record === null, 404, 'Patient not found.');

        return response()->json([
            'data' => PatientMedicationProfileResponseTransformer::transform($record),
        ], 201);
    }

    public function updateMedicationProfile(
        string $id,
        string $medicationId,
        UpdatePatientMedicationProfileRequest $request,
        UpdatePatientMedicationProfileUseCase $useCase
    ): JsonResponse {
        try {
            $record = $useCase->execute(
                patientId: $id,
                medicationId: $medicationId,
                payload: $this->toMedicationProfilePersistencePayload($request->validated()),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->tenantScopeRequiredError($exception->getMessage());
        }

        abort_if($record === null, 404, 'Patient medication profile entry not found.');

        return response()->json([
            'data' => PatientMedicationProfileResponseTransformer::transform($record),
        ]);
    }

    public function medicationSafetySummary(
        string $id,
        Request $request,
        GetPatientMedicationSafetySummaryUseCase $useCase
    ): JsonResponse {
        $summary = $useCase->execute($id, [
            'approved_medicine_catalog_item_id' => $request->query('approvedMedicineCatalogItemId'),
            'medication_code' => $request->query('medicationCode'),
            'medication_name' => $request->query('medicationName'),
            'dosage_instruction' => $request->query('dosageInstruction'),
            'clinical_indication' => $request->query('clinicalIndication'),
            'quantity_prescribed' => $request->query('quantityPrescribed'),
            'appointment_id' => $request->query('appointmentId'),
            'admission_id' => $request->query('admissionId'),
            'exclude_order_id' => $request->query('excludeOrderId'),
            'frequency' => $request->query('frequency'),
            'dose_quantity' => $request->query('doseQuantity'),
        ]);
        abort_if($summary === null, 404, 'Patient not found.');

        return response()->json([
            'data' => [
                'severity' => $summary['severity'],
                'blockers' => $summary['blockers'],
                'warnings' => $summary['warnings'],
                'rules' => $summary['rules'],
                'ruleGroups' => $summary['ruleGroups'] ?? [],
                'ruleSummary' => $summary['ruleSummary'] ?? null,
                'ruleCatalogVersion' => $summary['ruleCatalogVersion'] ?? null,
                'overrideOptions' => $summary['overrideOptions'],
                'patientContext' => $summary['patientContext'] ?? null,
                'allergyConflicts' => array_map(
                    [PatientAllergyResponseTransformer::class, 'transform'],
                    $summary['allergyConflicts'],
                ),
                'interactionConflicts' => array_map(
                    [MedicationInteractionConflictResponseTransformer::class, 'transform'],
                    $summary['interactionConflicts'] ?? [],
                ),
                'laboratorySignals' => array_map(
                    [MedicationLaboratorySignalResponseTransformer::class, 'transform'],
                    $summary['laboratorySignals'] ?? [],
                ),
                'policyRecommendation' => $summary['policyRecommendation'] ?? null,
                'activeProfileMatches' => array_map(
                    [PatientMedicationProfileResponseTransformer::class, 'transform'],
                    $summary['activeProfileMatches'],
                ),
                'activeMedicationProfile' => array_map(
                    [PatientMedicationProfileResponseTransformer::class, 'transform'],
                    $summary['activeMedicationProfile'],
                ),
                'matchingActiveOrders' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $summary['matchingActiveOrders'],
                ),
                'sameEncounterDuplicates' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $summary['sameEncounterDuplicates'],
                ),
                'recentPatientDuplicates' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $summary['recentPatientDuplicates'],
                ),
                'unreconciledDispensedOrders' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $summary['unreconciledDispensedOrders'],
                ),
                'suggestedActions' => $summary['suggestedActions'],
            ],
        ]);
    }

    public function medicationReconciliation(
        string $id,
        GetPatientMedicationReconciliationUseCase $useCase
    ): JsonResponse {
        $summary = $useCase->execute($id);
        abort_if($summary === null, 404, 'Patient not found.');

        return response()->json([
            'data' => [
                'counts' => $summary['counts'],
                'activeAllergies' => array_map(
                    [PatientAllergyResponseTransformer::class, 'transform'],
                    $summary['activeAllergies'],
                ),
                'activeMedicationProfile' => array_map(
                    [PatientMedicationProfileResponseTransformer::class, 'transform'],
                    $summary['activeMedicationProfile'],
                ),
                'activeDispensedOrders' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $summary['activeDispensedOrders'],
                ),
                'unreconciledDispensedOrders' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $summary['unreconciledDispensedOrders'],
                ),
                'continueCandidates' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $summary['continueCandidates'],
                ),
                'profileWithoutDispensedOrders' => array_map(
                    [PatientMedicationProfileResponseTransformer::class, 'transform'],
                    $summary['profileWithoutDispensedOrders'],
                ),
                'newOrdersToProfile' => array_map(
                    [PharmacyOrderResponseTransformer::class, 'transform'],
                    $summary['newOrdersToProfile'],
                ),
                'suggestedActions' => $summary['suggestedActions'],
            ],
        ]);
    }

    private function tenantScopeRequiredError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_SCOPE_REQUIRED',
            'message' => $message,
        ], 403);
    }

    private function toAllergyPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'substanceCode' => 'substance_code',
            'substanceName' => 'substance_name',
            'reaction' => 'reaction',
            'severity' => 'severity',
            'status' => 'status',
            'notedAt' => 'noted_at',
            'lastReactionAt' => 'last_reaction_at',
            'notes' => 'notes',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }

    private function toMedicationProfilePersistencePayload(array $validated): array
    {
        $fieldMap = [
            'medicationCode' => 'medication_code',
            'medicationName' => 'medication_name',
            'dose' => 'dose',
            'route' => 'route',
            'frequency' => 'frequency',
            'source' => 'source',
            'status' => 'status',
            'startedAt' => 'started_at',
            'stoppedAt' => 'stopped_at',
            'indication' => 'indication',
            'notes' => 'notes',
            'lastReconciledAt' => 'last_reconciled_at',
            'reconciliationNote' => 'reconciliation_note',
        ];

        $payload = [];
        foreach ($fieldMap as $requestKey => $storageKey) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $payload[$storageKey] = $validated[$requestKey];
        }

        return $payload;
    }
}
