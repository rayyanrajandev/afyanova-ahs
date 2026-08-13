<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyBillingDiscountRequest extends FormRequest
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
            'discount_policy_id' => ['required', 'uuid'],
            'invoice_id' => ['nullable', 'uuid', 'required_without:invoice_number'],
            'invoice_number' => ['nullable', 'string', 'required_without:invoice_id'],
            'reason' => ['sometimes', 'string'],
        ];
    }
}
