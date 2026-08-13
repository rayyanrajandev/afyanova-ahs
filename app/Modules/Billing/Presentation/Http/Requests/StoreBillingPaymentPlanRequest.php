<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillingPaymentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'billingInvoiceId' => ['nullable', 'uuid'],
            'cashBillingAccountId' => ['nullable', 'uuid'],
            'planName' => ['nullable', 'string', 'max:120'],
            'totalAmount' => ['nullable', 'numeric', 'gt:0'],
            'downPaymentAmount' => ['nullable', 'numeric', 'min:0'],
            'downPaymentPaymentMethod' => ['nullable', Rule::in(['cash', 'mobile_money', 'lipa_namba', 'card', 'bank_transfer', 'cheque', 'other'])],
            'downPaymentReference' => ['nullable', 'string', 'max:120'],
            'downPaymentPaidAt' => ['nullable', 'date'],
            'payerType' => ['nullable', Rule::in(['self_pay', 'insurance', 'employer', 'government', 'donor', 'other'])],
            'installmentCount' => ['required', 'integer', 'min:1', 'max:60'],
            'installmentFrequency' => ['required', Rule::in(['weekly', 'biweekly', 'monthly', 'quarterly', 'custom'])],
            'installmentIntervalDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            'firstDueDate' => ['required', 'date'],
            'termsAndNotes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
