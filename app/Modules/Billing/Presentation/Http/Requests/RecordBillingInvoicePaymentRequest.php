<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordBillingInvoicePaymentRequest extends FormRequest
{
    const METHODS_REQUIRING_REFERENCE = [
        'mobile_money',
        'lipa_namba',
        'card',
        'bank_transfer',
        'insurance_claim',
        'cheque',
        'waiver',
    ];

    const METHODS_REQUIRING_NOTE = ['waiver'];

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
            'amount' => ['required', 'numeric', 'gt:0'],
            'payerType' => ['required', 'string', Rule::in([
                'self_pay',
                'insurance',
                'employer',
                'government',
                'donor',
                'other',
            ])],
            'paymentMethod' => ['required', 'string', Rule::in([
                'cash',
                'mobile_money',
                'lipa_namba',
                'card',
                'bank_transfer',
                'insurance_claim',
                'cheque',
                'waiver',
                'other',
            ])],
            'paymentReference' => [
                Rule::requiredIf(fn () => in_array($this->paymentMethod, self::METHODS_REQUIRING_REFERENCE)),
                'string',
                'max:120',
            ],
            'note' => [
                Rule::requiredIf(fn () => in_array($this->paymentMethod, self::METHODS_REQUIRING_NOTE)),
                'string',
                'max:255',
            ],
            'paymentAt' => ['nullable', 'date'],
        ];
    }
}

