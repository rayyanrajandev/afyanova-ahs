<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePatientMedicationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patient.medications.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'medicationCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'medicationName' => ['sometimes', 'required', 'string', 'max:255'],
            'dose' => ['sometimes', 'nullable', 'string', 'max:255'],
            'route' => ['sometimes', 'nullable', 'string', 'max:100'],
            'frequency' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', Rule::in(['home_medication', 'chronic_therapy', 'external_prescription', 'discharge_medication', 'manual_entry'])],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'stopped', 'completed', 'entered_in_error'])],
            'startedAt' => ['sometimes', 'nullable', 'date'],
            'stoppedAt' => ['sometimes', 'nullable', 'date'],
            'indication' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lastReconciledAt' => ['sometimes', 'nullable', 'date'],
            'reconciliationNote' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
