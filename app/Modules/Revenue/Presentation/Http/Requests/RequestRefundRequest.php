<?php

namespace App\Modules\Revenue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'paymentId' => ['required', 'uuid'],
            'amountMinor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'serviceChargeId' => ['nullable', 'uuid'],
        ];
    }
}
