<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordBillingPaymentPlanPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payerType' => ['nullable', Rule::in(['self_pay', 'insurance', 'employer', 'government', 'donor', 'other'])],
            'paymentMethod' => ['required', Rule::in(['cash', 'mobile_money', 'lipa_namba', 'card', 'bank_transfer', 'insurance_claim', 'cheque', 'waiver', 'other'])],
            'paymentReference' => ['nullable', 'string', 'max:120'],
            'paymentAt' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
