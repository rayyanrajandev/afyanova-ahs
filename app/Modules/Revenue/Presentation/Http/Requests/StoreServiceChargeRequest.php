<?php

namespace App\Modules\Revenue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceChargeRequest extends FormRequest
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
            'patientId' => ['required', 'uuid'],
            'chargeableItemId' => ['required', 'uuid'],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.001', 'max:99999'],
            'encounterId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
        ];
    }
}
