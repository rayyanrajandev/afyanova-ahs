<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBillingCorporateInvoiceRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'billingPeriodStart' => ['required', 'date'],
            'billingPeriodEnd' => ['required', 'date'],
            'issueDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
