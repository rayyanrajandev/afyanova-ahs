<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundCashBillingPaymentRequest extends FormRequest
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
            'payment_id' => ['required', 'string'],
            'refund_amount' => ['required', 'numeric', 'min:0.01'],
            'refund_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
