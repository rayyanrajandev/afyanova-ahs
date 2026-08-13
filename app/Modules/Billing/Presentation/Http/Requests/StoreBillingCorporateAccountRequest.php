<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillingCorporateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'billingPayerContractId' => ['required', 'uuid', 'exists:billing_payer_contracts,id'],
            'accountCode' => ['nullable', 'string', 'max:60'],
            'accountName' => ['nullable', 'string', 'max:120'],
            'billingContactName' => ['nullable', 'string', 'max:120'],
            'billingContactEmail' => ['nullable', 'email', 'max:120'],
            'billingContactPhone' => ['nullable', 'string', 'max:40'],
            'billingCycleDay' => ['nullable', 'integer', 'min:1', 'max:31'],
            'settlementTermsDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended', 'closed'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
