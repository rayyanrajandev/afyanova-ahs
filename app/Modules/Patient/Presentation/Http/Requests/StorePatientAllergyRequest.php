<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientAllergyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patient.allergies.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'substanceCode' => ['nullable', 'string', 'max:100'],
            'substanceName' => ['required', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', Rule::in(['mild', 'moderate', 'severe', 'life_threatening', 'unknown'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'entered_in_error'])],
            'notedAt' => ['nullable', 'date'],
            'lastReactionAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
