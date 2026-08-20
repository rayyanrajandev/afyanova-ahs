<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientAllergyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['patient.allergies.record', 'patient.allergies.manage']) ?? false;
    }

    public function rules(): array
    {
        return [
            'substanceCode' => ['nullable', 'string', 'max:100'],
            'substanceName' => ['required', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:255'],
            'reactionCode' => ['nullable', 'string', 'max:100'],
            'severity' => ['nullable', Rule::in(['mild', 'moderate', 'severe', 'life_threatening', 'unknown'])],
            'clinicalStatus' => ['nullable', Rule::in(['active', 'inactive', 'resolved'])],
            'verificationStatus' => ['nullable', Rule::in(['unconfirmed', 'provisional', 'confirmed', 'refuted', 'entered_in_error'])],
            'type' => ['nullable', Rule::in(['allergy', 'intolerance'])],
            'category' => ['nullable', Rule::in(['medication', 'food', 'environment', 'biologic'])],
            'notedAt' => ['nullable', 'date'],
            'lastReactionAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:100'],
        ];
    }
}
