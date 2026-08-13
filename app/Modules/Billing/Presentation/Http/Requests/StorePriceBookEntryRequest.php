<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePriceBookEntryRequest extends FormRequest
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
            'currencyCode' => ['required', 'string', 'size:3'],
            'unitPrice' => ['required', 'numeric', 'min:0'],
            'taxRatePercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'isTaxable' => ['nullable', 'boolean'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after:effectiveFrom'],
            'payerContractId' => ['nullable', 'uuid', 'exists:billing_payer_contracts,id'],
        ];
    }
}
