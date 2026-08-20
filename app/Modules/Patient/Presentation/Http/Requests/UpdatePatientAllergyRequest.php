<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePatientAllergyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hasVerifyPermission = $this->user()?->canAny(['patient.allergies.verify', 'patient.allergies.manage']) ?? false;
        if ($this->has('verificationStatus') && in_array($this->input('verificationStatus'), ['confirmed', 'refuted', 'entered_in_error'], true)) {
            return $hasVerifyPermission;
        }

        return $this->user()?->canAny(['patient.allergies.record', 'patient.allergies.manage']) ?? false;
    }

    public function rules(): array
    {
        return [
            'substanceCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'substanceName' => ['sometimes', 'required', 'string', 'max:255'],
            'reaction' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reactionCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'severity' => ['sometimes', 'nullable', Rule::in(['mild', 'moderate', 'severe', 'life_threatening', 'unknown'])],
            'clinicalStatus' => ['sometimes', 'nullable', Rule::in(['active', 'inactive', 'resolved'])],
            'verificationStatus' => ['sometimes', 'nullable', Rule::in(['unconfirmed', 'provisional', 'confirmed', 'refuted', 'entered_in_error'])],
            'type' => ['sometimes', 'nullable', Rule::in(['allergy', 'intolerance'])],
            'category' => ['sometimes', 'nullable', Rule::in(['medication', 'food', 'environment', 'biologic'])],
            'notedAt' => ['sometimes', 'nullable', 'date'],
            'lastReactionAt' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
