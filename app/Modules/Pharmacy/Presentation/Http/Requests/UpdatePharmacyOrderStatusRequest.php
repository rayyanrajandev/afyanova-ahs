<?php

namespace App\Modules\Pharmacy\Presentation\Http\Requests;

use App\Modules\Pharmacy\Domain\ValueObjects\PharmacyOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePharmacyOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medication.dispense') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(PharmacyOrderStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled'],
            'quantityDispensed' => ['nullable', 'numeric', 'min:0'],
            'dispensedUnit' => ['nullable', 'string', 'max:40'],
            'dispensingNotes' => ['nullable', 'string', 'max:2000'],
            'batchId' => ['nullable', 'string', 'max:36'],
        ];
    }
}

