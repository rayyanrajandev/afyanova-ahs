<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\SubscriptionBillingCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currencyCode')) {
            $this->merge([
                'currencyCode' => strtoupper(trim((string) $this->input('currencyCode'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'billingCycle' => ['required', Rule::in(SubscriptionBillingCycle::values())],
            'priceAmount' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'currencyCode' => ['required', 'string', 'size:3'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'entitlements' => ['sometimes', 'array'],
            'entitlements.*.id' => ['required_with:entitlements', 'uuid'],
            'entitlements.*.enabled' => ['required_with:entitlements', 'boolean'],
            'entitlements.*.limitValue' => ['nullable', 'integer', 'min:0', 'max:999999999'],
        ];
    }
}
