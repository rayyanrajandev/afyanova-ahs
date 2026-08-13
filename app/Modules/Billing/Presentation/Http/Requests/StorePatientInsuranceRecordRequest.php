<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientInsuranceRecordRequest extends FormRequest
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
            'billingPayerContractId' => ['nullable', 'uuid', Rule::exists('billing_payer_contracts', 'id')],
            'insuranceType' => ['nullable', Rule::in((array) config('patient_insurance.insurance_type_values', ['insurance', 'government', 'employer', 'donor', 'other']))],
            'insuranceProvider' => ['nullable', 'string', 'max:160'],
            'providerCode' => ['nullable', 'string', 'max:80'],
            'planName' => ['nullable', 'string', 'max:160'],
            'policyNumber' => ['nullable', 'string', 'max:120'],
            'memberId' => ['nullable', 'required_without:cardNumber', 'string', 'max:120'],
            'principalMemberName' => ['nullable', 'string', 'max:160'],
            'relationshipToPrincipal' => ['nullable', Rule::in(['self', 'spouse', 'child', 'parent', 'dependent', 'other'])],
            'cardNumber' => ['nullable', 'required_without:memberId', 'string', 'max:120'],
            'effectiveDate' => ['nullable', 'date'],
            'expiryDate' => ['nullable', 'date', 'after_or_equal:effectiveDate'],
            'coverageLevel' => ['nullable', 'string', 'max:80'],
            'copayPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'coverageLimitAmount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'expired', 'cancelled'])],
            'verificationStatus' => ['nullable', Rule::in(['unverified', 'verified', 'failed', 'expired'])],
            'verificationDate' => ['nullable', 'date'],
            'verificationSource' => ['nullable', 'string', 'max:80'],
            'verificationReference' => ['nullable', 'string', 'max:160'],
            'lastVerifiedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
