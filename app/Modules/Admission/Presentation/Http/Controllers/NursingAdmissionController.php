<?php

namespace App\Modules\Admission\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admission\Application\Exceptions\AppointmentNotEligibleForAdmissionException;
use App\Modules\Admission\Application\Exceptions\InvalidAdmissionPlacementException;
use App\Modules\Admission\Application\Exceptions\PatientNotEligibleForAdmissionException;
use App\Modules\Admission\Application\UseCases\CreateAdmissionUseCase;
use App\Modules\Admission\Domain\ValueObjects\AdmissionStatus;
use App\Modules\Admission\Presentation\Http\Requests\StoreNursingAdmissionRequest;
use App\Modules\Admission\Presentation\Http\Transformers\AdmissionResponseTransformer;
use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Encounter\Domain\ValueObjects\EncounterType;
use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\Encounter\Infrastructure\Models\EncounterModel;
use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use Illuminate\Http\JsonResponse;

/**
 * Nursing workspace admission endpoint (POST /api/v1/nursing/admissions).
 *
 * Lets a nurse escalate a deteriorating OPD/emergency patient to an
 * inpatient admission from the Nursing workspace. Reuses the canonical
 * CreateAdmissionUseCase (no business-logic duplication), then — because
 * that use case only writes the admission row and never touches the
 * encounter — links the newly-created admission to the open encounter and
 * upgrades the encounter type to `inpatient` (2026-08-14). This is the
 * missing step that turns a walk-in OPD / triage patient into an admitted
 * inpatient.
 */
class NursingAdmissionController extends Controller
{
    public function __construct(
        private readonly EncounterResolverService $encounterResolver,
        private readonly RecordPatientFlowTransitionService $recordPatientFlowTransition,
    ) {}

    public function store(
        StoreNursingAdmissionRequest $request,
        CreateAdmissionUseCase $createAdmission,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $admission = $createAdmission->execute(
                payload: $this->toPersistencePayload($validated),
                actorId: $request->user()?->id,
            );
        } catch (TenantScopeRequiredForIsolationException $exception) {
            return $this->error($exception->getMessage(), 422, 'tenant_scope_required');
        } catch (PatientNotEligibleForAdmissionException $exception) {
            return $this->error($exception->getMessage(), 422, 'patient_not_eligible', 'patientId');
        } catch (AppointmentNotEligibleForAdmissionException $exception) {
            return $this->error($exception->getMessage(), 422, 'appointment_not_eligible', 'appointmentId');
        } catch (InvalidAdmissionPlacementException $exception) {
            return $this->error($exception->getMessage(), 422, 'invalid_placement');
        }

        // Link the new admission to the open encounter and upgrade its type
        // to inpatient. The walk-in OPD encounter already exists (created at
        // check-in); findOrCreateForVisit returns it (it won't duplicate).
        $encounter = $this->encounterResolver->findOrCreateForVisit(
            patientId: $validated['patientId'],
            appointmentId: $validated['appointmentId'] ?? null,
            admissionId: null,
            actorId: $request->user()?->id,
            requestedEncounterId: $validated['encounterId'],
        );

        $encounter->admission_id = $admission['id'];
        $encounter->type = EncounterType::INPATIENT->value;
        $encounter->save();

        // Admission recorded no flow event at all, so PatientFlowStep::ADMITTED
        // was unreachable and an admitted patient simply stopped moving on every
        // board — the visit's last recorded step stayed whatever it was when the
        // nurse or doctor decided to admit them (laboratory flow plan, phase 5).
        //
        // Skipped for an admission with no appointment: a transition needs an
        // appointment or a service request to belong to, and a direct admission
        // has neither.
        if (($validated['appointmentId'] ?? null) !== null) {
            $this->recordPatientFlowTransition->record(
                toStep: PatientFlowStep::ADMITTED,
                patientId: (string) $validated['patientId'],
                appointmentId: (string) $validated['appointmentId'],
                encounterId: (string) $encounter->id,
                actorId: $request->user()?->id,
                source: 'nursing.patient_admitted',
                reason: $validated['admissionReason'] ?? null,
                metadata: array_filter([
                    'admissionId' => $admission['id'] ?? null,
                    'ward' => $validated['ward'] ?? null,
                ], static fn ($value) => $value !== null),
                facilityId: $admission['facility_id'] ?? null,
            );
        }

        return response()->json([
            'data' => [
                'admission' => AdmissionResponseTransformer::transform($admission),
                'encounter' => [
                    'id' => $encounter->id,
                    'type' => $encounter->type,
                    'status' => $encounter->status,
                    'admissionId' => $encounter->admission_id,
                ],
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPersistencePayload(array $validated): array
    {
        $fieldMap = [
            'patientId' => 'patient_id',
            'appointmentId' => 'appointment_id',
            'attendingClinicianUserId' => 'attending_clinician_user_id',
            'ward' => 'ward',
            'bed' => 'bed',
            'bedResourceId' => 'bed_resource_id',
            'admittedAt' => 'admitted_at',
            'admissionReason' => 'admission_reason',
            'notes' => 'notes',
            'financialClass' => 'financial_coverage_type',
            'billingPayerContractId' => 'billing_payer_contract_id',
            'coverageReference' => 'coverage_reference',
            'coverageNotes' => 'coverage_notes',
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

    /**
     * @return JsonResponse
     */
    private function error(string $message, int $status, string $code, ?string $field = null): JsonResponse
    {
        $errors = $field !== null ? [$field => [$message]] : [$code => [$message]];

        return response()->json([
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
