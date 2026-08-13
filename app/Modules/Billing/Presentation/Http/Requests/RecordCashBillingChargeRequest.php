<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordCashBillingChargeRequest extends FormRequest
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
            'service_id' => ['sometimes', 'uuid'],
            'service_name' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'charge_date' => ['sometimes', 'date_format:Y-m-d H:i:s'],
            'reference_id' => ['sometimes', 'uuid'],
            'reference_type' => ['sometimes', 'string'],
            'description' => ['sometimes', 'string'],
        ];
    }
}
