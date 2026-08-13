<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePatientAllergyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patient.allergies.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'substanceCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'substanceName' => ['sometimes', 'required', 'string', 'max:255'],
            'reaction' => ['sometimes', 'nullable', 'string', 'max:255'],
            'severity' => ['sometimes', 'nullable', Rule::in(['mild', 'moderate', 'severe', 'life_threatening', 'unknown'])],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive', 'entered_in_error'])],
            'notedAt' => ['sometimes', 'nullable', 'date'],
            'lastReactionAt' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
