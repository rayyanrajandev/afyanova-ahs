<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use App\Modules\Pos\Domain\ValueObjects\PosCustomerType;
use App\Modules\Pos\Domain\ValueObjects\PosSaleChannel;
use App\Modules\Pos\Domain\ValueObjects\PosSaleLineType;
use App\Modules\Pos\Domain\ValueObjects\PosSalePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosSaleRequest extends FormRequest
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
            'saleChannel' => ['nullable', 'string', Rule::in(PosSaleChannel::values())],
            'customerType' => ['nullable', 'string', Rule::in(PosCustomerType::values())],
            'customerName' => ['nullable', 'string', 'max:120'],
            'customerReference' => ['nullable', 'string', 'max:120'],
            'currencyCode' => ['nullable', 'string', 'size:3'],
            'soldAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
            'lineItems' => ['required', 'array', 'min:1'],
            'lineItems.*.itemType' => ['nullable', 'string', Rule::in(PosSaleLineType::values())],
            'lineItems.*.itemReference' => ['nullable', 'string', 'max:120'],
            'lineItems.*.itemCode' => ['nullable', 'string', 'max:120'],
            'lineItems.*.itemName' => ['required', 'string', 'max:255'],
            'lineItems.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lineItems.*.unitPrice' => ['required', 'numeric', 'gt:0'],
            'lineItems.*.discountAmount' => ['nullable', 'numeric', 'gte:0'],
            'lineItems.*.taxAmount' => ['nullable', 'numeric', 'gte:0'],
            'lineItems.*.notes' => ['nullable', 'string', 'max:255'],
            'lineItems.*.metadata' => ['nullable', 'array'],
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
