<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessBillingRefundRequest extends FormRequest
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
            'mobile_money_reference' => ['sometimes', 'string'],
            'check_number' => ['sometimes', 'string'],
            'card_reference' => ['sometimes', 'string'],
            'actor_name' => ['sometimes', 'string'],
            'notes' => ['sometimes', 'string'],
        ];
    }
}
