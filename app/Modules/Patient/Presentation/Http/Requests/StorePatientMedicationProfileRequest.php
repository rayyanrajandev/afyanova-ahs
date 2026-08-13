<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientMedicationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patient.medications.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'medicationCode' => ['nullable', 'string', 'max:100'],
            'medicationName' => ['required', 'string', 'max:255'],
            'dose' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:100'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::in(['home_medication', 'chronic_therapy', 'external_prescription', 'discharge_medication', 'manual_entry'])],
            'status' => ['nullable', Rule::in(['active', 'stopped', 'completed', 'entered_in_error'])],
            'startedAt' => ['nullable', 'date'],
            'stoppedAt' => ['nullable', 'date'],
            'indication' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lastReconciledAt' => ['nullable', 'date'],
            'reconciliationNote' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
