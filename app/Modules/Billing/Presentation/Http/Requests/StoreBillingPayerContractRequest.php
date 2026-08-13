<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillingPayerContractRequest extends FormRequest
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
            'contractCode' => ['required', 'string', 'max:100'],
            'contractName' => ['required', 'string', 'max:255'],
            'payerType' => ['required', Rule::in($this->payerTypeValues())],
            'payerName' => ['required', 'string', 'max:160'],
            'payerPlanCode' => ['nullable', 'string', 'max:120'],
            'payerPlanName' => ['nullable', 'string', 'max:160'],
            'currencyCode' => ['required', 'string', 'size:3'],
            'defaultCoveragePercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'defaultCopayType' => ['nullable', Rule::in(['fixed', 'percentage', 'none'])],
            'defaultCopayValue' => ['nullable', 'numeric', 'min:0'],
            'requiresPreAuthorization' => ['nullable', 'boolean'],
            'claimSubmissionDeadlineDays' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'settlementCycleDays' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'termsAndNotes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
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
