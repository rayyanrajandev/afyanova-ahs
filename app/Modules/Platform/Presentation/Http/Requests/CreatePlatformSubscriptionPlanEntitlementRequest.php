<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePlatformSubscriptionPlanEntitlementRequest extends FormRequest
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
            'entitlementKey' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_.-]+$/'],
            'entitlementLabel' => ['required', 'string', 'max:160'],
            'entitlementGroup' => ['nullable', 'string', 'max:80'],
            'entitlementType' => ['nullable', Rule::in(['feature', 'limit'])],
            'limitValue' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}