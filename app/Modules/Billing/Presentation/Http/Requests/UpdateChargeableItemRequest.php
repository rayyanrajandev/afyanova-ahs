<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChargeableItemRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'catalogType' => ['sometimes', Rule::in([
                'lab_test',
                'radiology_procedure',
                'theatre_procedure',
                'clinical_procedure',
                'formulary_item',
                'consultation',
                'bed_day',
            ])],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'defaultUnit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'statusReason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
