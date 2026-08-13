<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordCashBillingPaymentRequest extends FormRequest
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
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_money', 'lipa_namba', 'check'])],
            'payment_reference' => ['sometimes', 'string'],
            'mobile_money_provider' => ['sometimes', 'string'],
            'mobile_money_transaction_id' => ['sometimes', 'string'],
            'card_last_four' => ['sometimes', 'string', 'size:4'],
            'check_number' => ['sometimes', 'string'],
            'paid_at' => ['sometimes', 'date_format:Y-m-d H:i:s'],
            'notes' => ['sometimes', 'string'],
        ];
    }
}
