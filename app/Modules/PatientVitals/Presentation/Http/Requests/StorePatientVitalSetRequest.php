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
            'recordedAt'           => ['nullable', 'date'],
            'temperatureC'         => ['nullable', 'numeric', 'min:25', 'max:45'],
            'heartRateBpm'         => ['nullable', 'integer', 'min:20', 'max:300'],
            'systolicBpMmhg'       => ['nullable', 'integer', 'min:40', 'max:300'],
            'diastolicBpMmhg'      => ['nullable', 'integer', 'min:20', 'max:200'],
            'oxygenSaturationPct'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'respiratoryRateBpm'   => ['nullable', 'integer', 'min:4', 'max:70'],
            'weightKg'             => ['nullable', 'numeric', 'min:0.3', 'max:700'],
        ];
    }
}
