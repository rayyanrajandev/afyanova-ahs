<?php

namespace App\Modules\PatientVitals\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointment\Application\UseCases\RecordAppointmentTriageUseCase;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\PatientVitals\Infrastructure\Models\PatientVitalSetModel;
use App\Modules\PatientVitals\Presentation\Http\Requests\StorePatientVitalSetRequest;
use App\Modules\PatientVitals\Presentation\Http\Requests\UpdatePatientVitalSetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PatientVitalSetController extends Controller
{
    private const OVERDUE_THRESHOLD_HOURS = 4;

    public function __construct(
        private readonly RecordAppointmentTriageUseCase $recordTriage,
        private readonly RecordPatientFlowTransitionService $recordPatientFlowTransition,
    ) {}

    /**
     * Record a set of vital signs for a patient.
     * Requires: inpatient.ward.create
     */
    public function store(StorePatientVitalSetRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $vitalSet = PatientVitalSetModel::create([
            'patient_id'            => $validated['patientId'],
            'admission_id'          => $validated['admissionId'] ?? null,
            'appointment_id'        => $validated['appointmentId'] ?? null,
            'recorded_by_user_id'   => $request->user()?->id,
            'recorded_at'           => $validated['recordedAt'] ?? now()->toDateTimeString(),
            'temperature_c'         => $validated['temperatureC'] ?? null,
            'heart_rate_bpm'        => $validated['heartRateBpm'] ?? null,
            'systolic_bp_mmhg'      => $validated['systolicBpMmhg'] ?? null,
            'diastolic_bp_mmhg'     => $validated['diastolicBpMmhg'] ?? null,
            'oxygen_saturation_pct' => $validated['oxygenSaturationPct'] ?? null,
            'respiratory_rate_bpm'  => $validated['respiratoryRateBpm'] ?? null,
            'weight_kg'             => $validated['weightKg'] ?? null,
            'height_cm'             => $validated['heightCm'] ?? null,
            'bmi'                   => $validated['bmi'] ?? $this->computeBmi($validated),
            'pain_score'            => $validated['painScore'] ?? null,
            'entry_state'           => 'active',
        ]);

        $this->advanceVisitWorkflowOnVitalsRecorded($request, $validated);

        return response()->json(['data' => [
            'id'         => $vitalSet->id,
            'patientId'  => $vitalSet->patient_id,
            'recordedAt' => $vitalSet->recorded_at?->toIso8601String(),
        ]], 201);
    }

    /**
     * Create a vital set from the patient chart context.
     * Requires: patients.update OR emergency.triage.create OR emergency.triage.update-status.
     */
    public function storeForChart(StorePatientVitalSetRequest $request): JsonResponse
    {
        abort_unless(Gate::any(['patient.vitals.record', 'emergency.triage.create', 'emergency.triage.update-status']), 403);

        $validated = $request->validated();

        $vitalSet = PatientVitalSetModel::create([
            'patient_id'            => $validated['patientId'],
            'appointment_id'        => $validated['appointmentId'] ?? null,
            'recorded_by_user_id'   => $request->user()?->id,
            'recorded_at'           => now()->toDateTimeString(),
            'temperature_c'         => $validated['temperatureC'] ?? null,
            'heart_rate_bpm'        => $validated['heartRateBpm'] ?? null,
            'systolic_bp_mmhg'      => $validated['systolicBpMmhg'] ?? null,
            'diastolic_bp_mmhg'     => $validated['diastolicBpMmhg'] ?? null,
            'oxygen_saturation_pct' => $validated['oxygenSaturationPct'] ?? null,
            'respiratory_rate_bpm'  => $validated['respiratoryRateBpm'] ?? null,
            'weight_kg'             => $validated['weightKg'] ?? null,
            'height_cm'             => $validated['heightCm'] ?? null,
            'bmi'                   => $validated['bmi'] ?? $this->computeBmi($validated),
            'pain_score'            => $validated['painScore'] ?? null,
            'entry_state'           => 'active',
        ]);

        $this->advanceVisitWorkflowOnVitalsRecorded($request, $validated);

        return response()->json(['data' => $this->transform($vitalSet)], 201);
    }

    private function advanceVisitWorkflowOnVitalsRecorded(StorePatientVitalSetRequest $request, array $validated): void
    {
        $summaryParts = [];
        if (! empty($validated['temperatureC'])) {
            $summaryParts[] = "T: {$validated['temperatureC']}°C";
        }
        if (! empty($validated['systolicBpMmhg']) && ! empty($validated['diastolicBpMmhg'])) {
            $summaryParts[] = "BP: {$validated['systolicBpMmhg']}/{$validated['diastolicBpMmhg']} mmHg";
        }
        if (! empty($validated['heartRateBpm'])) {
            $summaryParts[] = "HR: {$validated['heartRateBpm']} bpm";
        }
        if (! empty($validated['oxygenSaturationPct'])) {
            $summaryParts[] = "SpO2: {$validated['oxygenSaturationPct']}%";
        }
        if (! empty($validated['weightKg'])) {
            $summaryParts[] = "W: {$validated['weightKg']}kg";
        }
        $vitalsSummary = implode(', ', $summaryParts);

        $appointment = $this->resolveTriageFlowAppointment($validated);

        if ($appointment === null) {
            return;
        }

        // Vitals recorded, as its own timeline entry (2026-08-16 activity audit).
        // Previously the only trace was the triage handoff that followed, so the
        // Activity tab read "Triage completed" and never said observations had
        // been taken — the exact line the original flow ticket asked for
        // ("Nursing: vitals recorded at 10:50").
        //
        // Recorded before the handoff so the two appear in the order they
        // happened. It carries no step change of its own: taking observations
        // does not move the patient, the handoff immediately after does. Passing
        // the visit's current step keeps this an event on the timeline rather
        // than a phantom transition.
        $this->recordPatientFlowTransition->record(
            toStep: PatientFlowStep::forAppointment($appointment) ?? PatientFlowStep::WAITING_TRIAGE,
            patientId: (string) $appointment->patient_id,
            appointmentId: (string) $appointment->id,
            actorId: $request->user()?->id,
            source: 'nursing.vitals_recorded',
            metadata: array_filter([
                'summary' => $vitalsSummary !== '' ? $vitalsSummary : null,
            ], static fn ($value) => $value !== null),
            facilityId: $appointment->facility_id,
            // Taking observations does not move the patient — the handoff
            // immediately after does — so this is recorded as a same-step event
            // rather than a phantom transition.
            allowSameStep: true,
        );

        // Delegated to RecordAppointmentTriageUseCase rather than written here
        // (2026-08-16 patient-flow audit, finding 02). This method previously
        // called $appointment->update(['status' => WAITING_PROVIDER]) directly,
        // which meant: no AppointmentStatus::canTransitionTo() guard, no row in
        // appointment_audit_logs — so the transition was invisible to every
        // audit surface and CSV export in the system — and a PatientFlowBoardUpdated
        // fired alongside AppointmentStatusChanged, which
        // BroadcastPatientFlowBoardUpdate already translates into exactly that
        // event, refreshing every board twice per vitals save.
        //
        // The use case owns the routing requirement, the audit row, the domain
        // event and the flow-log entry. Recording vitals is not a special case
        // that gets to skip them.
        try {
            $this->recordTriage->execute(
                id: (string) $appointment->id,
                triageVitalsSummary: $vitalsSummary !== '' ? $vitalsSummary : (string) $appointment->triage_vitals_summary,
                triageNotes: $appointment->triage_notes,
                triageCategory: $appointment->triage_category,
                // Routing is preserved, never invented here: this path is a nurse
                // recording observations, not making a routing decision.
                routing: [
                    // The nurse's routing choice, when the triage form offered
                    // one; otherwise whatever the visit already carries.
                    'department_id' => $validated['departmentId'] ?? $appointment->department_id,
                    'department' => $appointment->department,
                    'clinician_user_id' => $appointment->clinician_user_id,
                ],
                actorId: $request->user()?->id,
                // Recording observations is not the moment a nurse routes a
                // patient, and ordinary walk-ins are created with no department
                // by design (RegisterWalkInAndCheckInUseCase). Requiring routing
                // here stalled every walk-in in waiting_triage after its vitals
                // were taken: triage never completed, the queue badge never
                // advanced, and the nursing header kept offering "Record Vitals"
                // for a patient whose vitals were already on file (2026-08-16).
                // Routing stays whatever it already is — the patient lands in the
                // general provider pool, which is where they belonged anyway.
                requireRouting: false,
            );
        } catch (ValidationException $exception) {
            // Reached only when the visit is not in the triage flow at all —
            // resolveTriageFlowAppointment() already filters to those statuses,
            // so this is a genuine race (someone moved the visit mid-request),
            // not the ordinary no-department case.
            Log::info('Vitals recorded without advancing the visit', [
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'errors' => $exception->errors(),
            ]);
        }
    }

    /**
     * The visit these vitals should advance, or null when there isn't one.
     *
     * Only visits actually sitting in triage qualify. The previous version
     * loaded an explicitly-supplied appointmentId with no status filter at all,
     * so recording vitals for a patient already `in_consultation` reset them to
     * `waiting_provider` — pulling a patient out of the doctor's room mid-visit.
     * The fallback lookup below always filtered correctly; the explicit path did
     * not, and the explicit path is the one the nursing workspace uses.
     */
    private function resolveTriageFlowAppointment(array $validated): ?AppointmentModel
    {
        $triageFlowStatuses = [
            AppointmentStatus::WAITING_TRIAGE->value,
            AppointmentStatus::WAITING_PROVIDER->value,
        ];

        $appointmentId = $validated['appointmentId'] ?? null;

        if ($appointmentId !== null) {
            $appointment = AppointmentModel::where('id', $appointmentId)
                ->whereIn('status', $triageFlowStatuses)
                ->first();

            if ($appointment !== null) {
                return $appointment;
            }
        }

        return AppointmentModel::where('patient_id', $validated['patientId'])
            ->whereIn('status', $triageFlowStatuses)
            ->latest('scheduled_at')
            ->first();
    }

    /**
     * Get all active vital sets for a patient, newest first, plus the latest shortcut.
     */
    public function latestForPatient(string $patientId): JsonResponse
    {
        $all = PatientVitalSetModel::where('patient_id', $patientId)
            ->where('entry_state', 'active')
            ->latest('recorded_at')
            ->get()
            ->map(fn (PatientVitalSetModel $m) => $this->transform($m))
            ->values();

        return response()->json(['data' => [
            'latest'  => $all->first(),
            'history' => $all->slice(1)->values(),
        ]]);
    }

    /**
     * Update an existing vital set.
     * Requires: patients.update OR emergency.triage.create OR emergency.triage.update-status.
     */
    public function update(UpdatePatientVitalSetRequest $request, string $id): JsonResponse
    {
        abort_unless(Gate::any(['patient.vitals.record', 'emergency.triage.create', 'emergency.triage.update-status']), 403);

        $vitalSet = PatientVitalSetModel::where('id', $id)
            ->where('entry_state', 'active')
            ->firstOrFail();

        $validated = $request->validated();

        $weightKg = array_key_exists('weightKg', $validated) ? $validated['weightKg'] : $vitalSet->weight_kg;
        $heightCm = array_key_exists('heightCm', $validated) ? $validated['heightCm'] : $vitalSet->height_cm;
        $bmi = array_key_exists('bmi', $validated) ? $validated['bmi'] : $this->bmiFrom($weightKg, $heightCm);

        $vitalSet->update([
            'temperature_c'         => array_key_exists('temperatureC', $validated) ? $validated['temperatureC'] : $vitalSet->temperature_c,
            'heart_rate_bpm'        => array_key_exists('heartRateBpm', $validated) ? $validated['heartRateBpm'] : $vitalSet->heart_rate_bpm,
            'systolic_bp_mmhg'      => array_key_exists('systolicBpMmhg', $validated) ? $validated['systolicBpMmhg'] : $vitalSet->systolic_bp_mmhg,
            'diastolic_bp_mmhg'     => array_key_exists('diastolicBpMmhg', $validated) ? $validated['diastolicBpMmhg'] : $vitalSet->diastolic_bp_mmhg,
            'oxygen_saturation_pct' => array_key_exists('oxygenSaturationPct', $validated) ? $validated['oxygenSaturationPct'] : $vitalSet->oxygen_saturation_pct,
            'respiratory_rate_bpm'  => array_key_exists('respiratoryRateBpm', $validated) ? $validated['respiratoryRateBpm'] : $vitalSet->respiratory_rate_bpm,
            'weight_kg'             => $weightKg,
            'height_cm'             => $heightCm,
            'bmi'                   => $bmi,
            'pain_score'            => array_key_exists('painScore', $validated) ? $validated['painScore'] : $vitalSet->pain_score,
        ]);

        $vitalSet->refresh();

        return response()->json(['data' => $this->transform($vitalSet)]);
    }

    /**
     * Return a count of admitted patients whose last recorded vitals are older than the threshold.
     * Requires: inpatient.ward.read
     */
    public function overdueSummary(): JsonResponse
    {
        $thresholdHours = self::OVERDUE_THRESHOLD_HOURS;
        $cutoff = now()->subHours($thresholdHours);

        $admittedPatientIds = DB::table('admissions')
            ->where('status', 'admitted')
            ->distinct()
            ->pluck('patient_id')
            ->values();

        $totalAdmitted = $admittedPatientIds->count();

        if ($totalAdmitted === 0) {
            return response()->json(['data' => [
                'overdue_count'   => 0,
                'threshold_hours' => $thresholdHours,
                'total_admitted'  => 0,
            ]]);
        }

        $patientsWithRecentVitals = DB::table('patient_vital_sets')
            ->whereIn('patient_id', $admittedPatientIds)
            ->where('recorded_at', '>=', $cutoff)
            ->where('entry_state', 'active')
            ->distinct()
            ->pluck('patient_id')
            ->values()
            ->flip();

        $overdueCount = $admittedPatientIds->filter(
            fn (string $id) => ! $patientsWithRecentVitals->has($id),
        )->count();

        return response()->json(['data' => [
            'overdue_count'   => $overdueCount,
            'threshold_hours' => $thresholdHours,
            'total_admitted'  => $totalAdmitted,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(PatientVitalSetModel $vitalSet): array
    {
        return [
            'id'                  => $vitalSet->id,
            'patientId'           => $vitalSet->patient_id,
            'recordedByUserId'    => $vitalSet->recorded_by_user_id,
            'recordedAt'          => $vitalSet->recorded_at?->toIso8601String(),
            'temperatureC'        => $vitalSet->temperature_c,
            'heartRateBpm'        => $vitalSet->heart_rate_bpm,
            'systolicBpMmhg'      => $vitalSet->systolic_bp_mmhg,
            'diastolicBpMmhg'     => $vitalSet->diastolic_bp_mmhg,
            'oxygenSaturationPct' => $vitalSet->oxygen_saturation_pct,
            'respiratoryRateBpm'  => $vitalSet->respiratory_rate_bpm,
            'weightKg'            => $vitalSet->weight_kg,
            'heightCm'            => $vitalSet->height_cm,
            'bmi'                 => $vitalSet->bmi,
            'painScore'           => $vitalSet->pain_score,
            'entryState'          => $vitalSet->entry_state,
            'updatedAt'           => $vitalSet->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Derive BMI (kg/m²) from the validated payload if height and weight are
     * present, returning null when either is missing or height is zero.
     *
     * @param  array<string, mixed>  $validated
     */
    private function computeBmi(array $validated): ?float
    {
        $weightKg = $validated['weightKg'] ?? null;
        $heightCm = $validated['heightCm'] ?? null;

        return $this->bmiFrom($weightKg, $heightCm);
    }

    /**
     * @param  mixed  $weightKg
     * @param  mixed  $heightCm
     */
    private function bmiFrom($weightKg, $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null || (float) $heightCm <= 0) {
            return null;
        }

        $heightM = (float) $heightCm / 100;

        return round((float) $weightKg / ($heightM * $heightM), 2);
    }
}
