<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBillingDiscountPoliciesRequest extends FormRequest
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
            'q' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'all'])],
            'availability' => ['sometimes', Rule::in(['current', 'scheduled', 'expired', 'all'])],
        ];
    }
}
