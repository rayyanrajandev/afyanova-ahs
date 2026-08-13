<?php

namespace App\Modules\Pharmacy\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePharmacyOrderPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'formularyDecisionStatus' => ['required', Rule::in(['not_reviewed', 'formulary', 'non_formulary', 'restricted'])],
            'formularyDecisionReason' => ['nullable', 'string', 'max:1000', 'required_if:formularyDecisionStatus,non_formulary,restricted'],
            'substitutionAllowed' => ['required', 'boolean'],
            'substitutionMade' => ['required', 'boolean'],
            'substitutedMedicationCode' => ['nullable', 'string', 'max:100', 'required_if:substitutionMade,true'],
            'substitutedMedicationName' => ['nullable', 'string', 'max:255', 'required_if:substitutionMade,true'],
            'substitutionReason' => ['nullable', 'string', 'max:1000', 'required_if:substitutionMade,true'],
        ];
    }
}

