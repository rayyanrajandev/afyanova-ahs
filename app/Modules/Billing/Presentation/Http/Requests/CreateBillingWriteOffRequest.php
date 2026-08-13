<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBillingWriteOffRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'billing_invoice_id' => ['required', 'string', 'uuid'],
            'patient_id' => ['required', 'string', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
