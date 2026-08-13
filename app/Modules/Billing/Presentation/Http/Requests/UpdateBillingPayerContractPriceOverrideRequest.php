<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBillingPayerContractPriceOverrideRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'billingServiceCatalogItemId',
        'chargeableItemId',
        'serviceCode',
        'serviceName',
        'serviceType',
        'department',
        'pricingStrategy',
        'overrideValue',
        'effectiveFrom',
        'effectiveTo',
        'overrideNotes',
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
            'chargeableItemId' => ['nullable', 'uuid', 'exists:chargeable_items,id'],
            'serviceCode' => ['sometimes', 'string', 'max:100'],
            'serviceName' => ['nullable', 'string', 'max:255'],
            'serviceType' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:120'],
            'pricingStrategy' => ['sometimes', Rule::in(['fixed_price', 'discount_percent', 'markup_percent'])],
            'overrideValue' => ['nullable', 'numeric', 'min:0'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'overrideNotes' => ['nullable', 'string', 'max:3000'],
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

            $strategy = (string) ($this->input('pricingStrategy') ?? '');
            $value = $this->input('overrideValue');
            if ($value !== null && $value !== '' && $strategy === 'discount_percent' && (float) $value > 100) {
                $validator->errors()->add('overrideValue', 'Discount percent cannot exceed 100.');
            }
        });
    }
}
