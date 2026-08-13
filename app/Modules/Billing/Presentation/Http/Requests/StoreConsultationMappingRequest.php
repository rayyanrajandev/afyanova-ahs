<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsultationMappingRequest extends FormRequest
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
            'chargeable_item_id' => ['required', 'uuid', 'exists:chargeable_items,id'],
            'clinician_tier' => ['required', Rule::in(['CO', 'AMO', 'MD', 'SPECIALIST'])],
            'department' => [
                'required',
                'string',
                Rule::unique('consultation_mappings', 'department')
                    ->where(fn ($query) => $query->where('clinician_tier', $this->input('clinician_tier'))),
            ],
        ];
    }
}
