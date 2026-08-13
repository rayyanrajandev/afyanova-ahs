<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use App\Modules\Billing\Domain\ValueObjects\BillingPayerContractPriceOverrideStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingPayerContractPriceOverrideStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(BillingPayerContractPriceOverrideStatus::values())],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:status,inactive,retired'],
        ];
    }
}
