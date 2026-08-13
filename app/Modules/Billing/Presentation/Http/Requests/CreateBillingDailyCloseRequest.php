<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBillingDailyCloseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'closed_at' => ['required', 'date'],
            'opened_at' => ['required', 'date'],
            'total_cash_amount' => ['nullable', 'numeric', 'min:0'],
            'total_card_amount' => ['nullable', 'numeric', 'min:0'],
            'total_mpesa_amount' => ['nullable', 'numeric', 'min:0'],
            'total_other_amount' => ['nullable', 'numeric', 'min:0'],
            'total_refunds' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
