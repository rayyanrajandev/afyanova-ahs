<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use App\Modules\Pos\Domain\ValueObjects\PosSalePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePharmacyOtcSaleRequest extends FormRequest
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
            'registerId' => ['required', 'uuid'],
            'patientId' => ['nullable', 'uuid'],
            'customerName' => ['nullable', 'string', 'max:120'],
            'customerReference' => ['nullable', 'string', 'max:120'],
            'currencyCode' => ['nullable', 'string', 'size:3'],
            'soldAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.catalogItemId' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unitPrice' => ['nullable', 'numeric', 'gt:0'],
            'items.*.discountAmount' => ['nullable', 'numeric', 'gte:0'],
            'items.*.taxAmount' => ['nullable', 'numeric', 'gte:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.paymentMethod' => ['required', 'string', Rule::in(PosSalePaymentMethod::values())],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.paymentReference' => ['nullable', 'string', 'max:120'],
            'payments.*.paidAt' => ['nullable', 'date'],
            'payments.*.note' => ['nullable', 'string', 'max:255'],
            'payments.*.metadata' => ['nullable', 'array'],
        ];
    }
}
