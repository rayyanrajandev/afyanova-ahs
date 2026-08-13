<?php

namespace App\Modules\ClaimsInsurance\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClaimsInsuranceCaseRequest extends FormRequest
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
            'invoiceId' => ['required', 'uuid'],
            'payerType' => ['required', Rule::in($this->payerTypeValues())],
            'payerName' => ['nullable', 'string', 'max:120'],
            'payerPlanName' => ['nullable', 'string', 'max:160'],
            'payerReference' => ['nullable', 'string', 'max:120'],
            'patientInsuranceRecordId' => ['nullable', 'uuid'],
            'memberId' => ['nullable', 'string', 'max:120'],
            'policyNumber' => ['nullable', 'string', 'max:120'],
            'cardNumber' => ['nullable', 'string', 'max:120'],
            'verificationReference' => ['nullable', 'string', 'max:160'],
            'submittedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function payerTypeValues(): array
    {
        return ['self_pay', 'insurance', 'employer', 'government', 'donor', 'other'];
    }
}
