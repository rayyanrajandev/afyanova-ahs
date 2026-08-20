<?php

namespace App\Modules\PatientVitals\Application\Services;

use App\Modules\PatientVitals\Infrastructure\Models\PatientVitalSetModel;

/**
 * The one-line observation summary that rides along with a triage handoff.
 *
 * Extracted because it is now built from two places: the request that records
 * the vitals, and — when the handoff had to be deferred because the visit was
 * unpaid — from the stored set once the charge clears. Two copies of a display
 * format drift, and this one is written onto the appointment where a clinician
 * reads it.
 */
class VitalsSummaryFormatter
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function fromRequest(array $validated): string
    {
        return $this->compose(
            temperature: $validated['temperatureC'] ?? null,
            systolic: $validated['systolicBpMmhg'] ?? null,
            diastolic: $validated['diastolicBpMmhg'] ?? null,
            heartRate: $validated['heartRateBpm'] ?? null,
            oxygen: $validated['oxygenSaturationPct'] ?? null,
            weight: $validated['weightKg'] ?? null,
        );
    }

    public function fromRecord(PatientVitalSetModel $vitals): string
    {
        return $this->compose(
            temperature: $vitals->temperature_c,
            systolic: $vitals->systolic_bp_mmhg,
            diastolic: $vitals->diastolic_bp_mmhg,
            heartRate: $vitals->heart_rate_bpm,
            oxygen: $vitals->oxygen_saturation_pct,
            weight: $vitals->weight_kg,
        );
    }

    private function compose(
        mixed $temperature,
        mixed $systolic,
        mixed $diastolic,
        mixed $heartRate,
        mixed $oxygen,
        mixed $weight,
    ): string {
        $parts = [];

        if (! empty($temperature)) {
            $parts[] = "T: {$temperature}°C";
        }

        if (! empty($systolic) && ! empty($diastolic)) {
            $parts[] = "BP: {$systolic}/{$diastolic} mmHg";
        }

        if (! empty($heartRate)) {
            $parts[] = "HR: {$heartRate} bpm";
        }

        if (! empty($oxygen)) {
            $parts[] = "SpO2: {$oxygen}%";
        }

        if (! empty($weight)) {
            $parts[] = "W: {$weight}kg";
        }

        return implode(', ', $parts);
    }
}
