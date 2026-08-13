<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordBillingCorporateRunPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'paymentMethod' => ['required', Rule::in(['cash', 'mobile_money', 'lipa_namba', 'card', 'bank_transfer', 'insurance_claim', 'cheque', 'other'])],
            'paymentReference' => ['nullable', 'string', 'max:120'],
            'paymentAt' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
