<?php

namespace App\Modules\ClaimsInsurance\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClaimsInsuranceCaseReconciliationRequest extends FormRequest
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
            'settledAmount' => ['required', 'numeric', 'min:0'],
            'settledAt' => ['nullable', 'date'],
            'settlementReference' => ['nullable', 'string', 'max:120'],
            'reconciliationNotes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
