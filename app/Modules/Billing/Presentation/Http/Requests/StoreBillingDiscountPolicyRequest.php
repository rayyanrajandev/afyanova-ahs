<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillingDiscountPolicyRequest extends FormRequest
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
            'code' => ['required', 'string', 'unique:billing_discount_policies,code'],
            'name' => ['required', 'string'],
            'description' => ['sometimes', 'string'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed', 'full_waiver', 'tiered'])],
            'discount_value' => ['sometimes', 'numeric', 'min:0'],
            'discount_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'applicable_services' => ['sometimes', 'array'],
            'auto_apply' => ['sometimes', 'boolean'],
            'requires_approval_above_amount' => ['sometimes', 'numeric', 'min:0'],
            'active_from_date' => ['sometimes', 'date_format:Y-m-d H:i:s'],
            'active_to_date' => ['sometimes', 'date_format:Y-m-d H:i:s', 'nullable'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
