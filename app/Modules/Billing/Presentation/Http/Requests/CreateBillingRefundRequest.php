<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBillingRefundRequest extends FormRequest
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
            'invoice_id' => ['nullable', 'uuid', 'required_without:invoice_number'],
            'invoice_number' => ['nullable', 'string', 'required_without:invoice_id'],
            'payment_id' => ['sometimes', 'uuid'],
            'refund_reason' => ['required', Rule::in(['overpayment', 'service_cancelled', 'insurance_adjustment', 'error'])],
            'refund_amount' => ['required', 'numeric', 'min:0.01'],
            'refund_method' => ['sometimes', Rule::in(['cash', 'check', 'mobile_money', 'credit_note'])],
            'mobile_money_provider' => ['sometimes', 'string'],
            'mobile_money_reference' => ['sometimes', 'string'],
            'card_reference' => ['sometimes', 'string'],
            'check_number' => ['sometimes', 'string'],
            'notes' => ['sometimes', 'string'],
        ];
    }
}
