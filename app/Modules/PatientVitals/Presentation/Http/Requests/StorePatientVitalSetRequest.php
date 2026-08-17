<?php

namespace App\Modules\PatientVitals\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientVitalSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'patientId'            => ['required', 'string', 'uuid'],
            'admissionId'          => ['nullable', 'string', 'uuid'],
            'appointmentId'        => ['nullable', 'string', 'uuid'],
            // Routing target chosen at triage completion (2026-08-16). Recording
            // vitals is the moment a nurse knows where the patient should go —
            // walk-ins are registered with no department at all, and nothing
            // downstream ever asked for one, so they reached the provider queue
            // belonging to no clinic.
            'departmentId'         => ['nullable', 'string', 'uuid'],
            'recordedAt'           => ['nullable', 'date'],
            'temperatureC'         => ['nullable', 'numeric', 'min:25', 'max:45'],
            'heartRateBpm'         => ['nullable', 'integer', 'min:20', 'max:300'],
            'systolicBpMmhg'       => ['nullable', 'integer', 'min:40', 'max:300'],
            'diastolicBpMmhg'      => ['nullable', 'integer', 'min:20', 'max:200'],
            'oxygenSaturationPct'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'respiratoryRateBpm'   => ['nullable', 'integer', 'min:4', 'max:70'],
            'weightKg'             => ['nullable', 'numeric', 'min:0.3', 'max:700'],
            'heightCm'             => ['nullable', 'numeric', 'min:30', 'max:250'],
            'bmi'                  => ['nullable', 'numeric', 'min:5', 'max:100'],
            'painScore'            => ['nullable', 'integer', 'min:0', 'max:10'],
        ];
    }
}
