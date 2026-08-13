<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBillingPayerContractRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'contractCode',
        'contractName',
        'payerType',
        'payerName',
        'payerPlanCode',
        'payerPlanName',
        'currencyCode',
        'defaultCoveragePercent',
        'defaultCopayType',
        'defaultCopayValue',
        'requiresPreAuthorization',
        'claimSubmissionDeadlineDays',
        'settlementCycleDays',
        'effectiveFrom',
        'effectiveTo',
        'termsAndNotes',
        'metadata',
    ];

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
            'contractCode' => ['sometimes', 'string', 'max:100'],
            'contractName' => ['sometimes', 'string', 'max:255'],
            'payerType' => ['sometimes', Rule::in($this->payerTypeValues())],
            'payerName' => ['sometimes', 'string', 'max:160'],
            'payerPlanCode' => ['nullable', 'string', 'max:120'],
            'payerPlanName' => ['nullable', 'string', 'max:160'],
            'currencyCode' => ['sometimes', 'string', 'size:3'],
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
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'reason' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requestedKeys = array_keys($this->all());
            $hasAllowedField = count(array_intersect($requestedKeys, self::ALLOWED_FIELDS)) > 0;

            if (! $hasAllowedField) {
                $validator->errors()->add('payload', 'At least one updatable field is required.');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function payerTypeValues(): array
    {
        return ['self_pay', 'insurance', 'employer', 'government', 'donor', 'other'];
    }
}
