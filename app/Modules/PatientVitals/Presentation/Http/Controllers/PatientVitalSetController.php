<?php

namespace App\Modules\PatientVitals\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PatientVitals\Infrastructure\Models\PatientVitalSetModel;
use App\Modules\PatientVitals\Presentation\Http\Requests\StorePatientVitalSetRequest;
use App\Modules\PatientVitals\Presentation\Http\Requests\UpdatePatientVitalSetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PatientVitalSetController extends Controller
{
    private const OVERDUE_THRESHOLD_HOURS = 4;

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
            'entry_state'           => 'active',
        ]);

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
            'entry_state'           => 'active',
        ]);

        return response()->json(['data' => $this->transform($vitalSet)], 201);
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

        $vitalSet->update([
            'temperature_c'         => array_key_exists('temperatureC', $validated) ? $validated['temperatureC'] : $vitalSet->temperature_c,
            'heart_rate_bpm'        => array_key_exists('heartRateBpm', $validated) ? $validated['heartRateBpm'] : $vitalSet->heart_rate_bpm,
            'systolic_bp_mmhg'      => array_key_exists('systolicBpMmhg', $validated) ? $validated['systolicBpMmhg'] : $vitalSet->systolic_bp_mmhg,
            'diastolic_bp_mmhg'     => array_key_exists('diastolicBpMmhg', $validated) ? $validated['diastolicBpMmhg'] : $vitalSet->diastolic_bp_mmhg,
            'oxygen_saturation_pct' => array_key_exists('oxygenSaturationPct', $validated) ? $validated['oxygenSaturationPct'] : $vitalSet->oxygen_saturation_pct,
            'respiratory_rate_bpm'  => array_key_exists('respiratoryRateBpm', $validated) ? $validated['respiratoryRateBpm'] : $vitalSet->respiratory_rate_bpm,
            'weight_kg'             => array_key_exists('weightKg', $validated) ? $validated['weightKg'] : $vitalSet->weight_kg,
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
            'entryState'          => $vitalSet->entry_state,
            'updatedAt'           => $vitalSet->updated_at?->toIso8601String(),
        ];
    }
}
