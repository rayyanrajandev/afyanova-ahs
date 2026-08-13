<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBillingPayerAuthorizationRuleRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'billingServiceCatalogItemId',
        'ruleCode',
        'ruleName',
        'serviceCode',
        'serviceType',
        'department',
        'diagnosisCode',
        'priority',
        'minPatientAgeYears',
        'maxPatientAgeYears',
        'gender',
        'amountThreshold',
        'quantityLimit',
        'coverageDecision',
        'coveragePercentOverride',
        'copayType',
        'copayValue',
        'benefitLimitAmount',
        'effectiveFrom',
        'effectiveTo',
        'requiresAuthorization',
        'autoApprove',
        'authorizationValidityDays',
        'ruleNotes',
        'ruleExpression',
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
            'billingServiceCatalogItemId' => ['nullable', 'uuid'],
            'ruleCode' => ['sometimes', 'string', 'max:100'],
            'ruleName' => ['sometimes', 'string', 'max:255'],
            'serviceCode' => ['nullable', 'string', 'max:100'],
            'serviceType' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:120'],
            'diagnosisCode' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', Rule::in(['routine', 'urgent', 'stat', 'emergency'])],
            'minPatientAgeYears' => ['nullable', 'integer', 'min:0', 'max:130'],
            'maxPatientAgeYears' => ['nullable', 'integer', 'min:0', 'max:130'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown', 'any'])],
            'amountThreshold' => ['nullable', 'numeric', 'min:0'],
            'quantityLimit' => ['nullable', 'integer', 'min:1'],
            'coverageDecision' => ['nullable', Rule::in(['inherit', 'covered', 'covered_with_rule', 'excluded', 'manual_review'])],
            'coveragePercentOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'copayType' => ['nullable', Rule::in(['none', 'fixed', 'percentage'])],
            'copayValue' => ['nullable', 'numeric', 'min:0'],
            'benefitLimitAmount' => ['nullable', 'numeric', 'min:0'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'requiresAuthorization' => ['nullable', 'boolean'],
            'autoApprove' => ['nullable', 'boolean'],
            'authorizationValidityDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'ruleNotes' => ['nullable', 'string', 'max:3000'],
            'ruleExpression' => ['nullable', 'array'],
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

            $minAge = $this->input('minPatientAgeYears');
            $maxAge = $this->input('maxPatientAgeYears');
            if ($minAge !== null && $maxAge !== null && (int) $maxAge < (int) $minAge) {
                $validator->errors()->add('maxPatientAgeYears', 'maxPatientAgeYears must be greater than or equal to minPatientAgeYears.');
            }

            $copayType = (string) $this->input('copayType');
            $copayValue = $this->input('copayValue');
            if ($copayType === 'percentage' && $copayValue !== null && $copayValue !== '' && (float) $copayValue > 100) {
                $validator->errors()->add('copayValue', 'Percentage co-pay cannot exceed 100.');
            }
        });
    }
}
