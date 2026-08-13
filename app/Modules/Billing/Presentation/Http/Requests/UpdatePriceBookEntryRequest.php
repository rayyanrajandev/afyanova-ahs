<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePriceBookEntryRequest extends FormRequest
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
            'currencyCode' => ['sometimes', 'string', 'size:3'],
            'unitPrice' => ['sometimes', 'numeric', 'min:0'],
            'taxRatePercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'isTaxable' => ['nullable', 'boolean'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after:effectiveFrom'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'statusReason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
