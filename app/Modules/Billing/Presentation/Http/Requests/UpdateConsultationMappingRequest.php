<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use App\Modules\Billing\Infrastructure\Models\ConsultationMappingModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConsultationMappingRequest extends FormRequest
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
        $mappingId = $this->route('mappingId');
        $tier = $this->input('clinician_tier')
            ?? ConsultationMappingModel::find($mappingId)?->clinician_tier;

        return [
            'chargeable_item_id' => ['sometimes', 'required', 'uuid', 'exists:chargeable_items,id'],
            'clinician_tier' => ['sometimes', 'required', Rule::in(['CO', 'AMO', 'MD', 'SPECIALIST'])],
            'department' => [
                'sometimes',
                'required',
                'string',
                Rule::unique('consultation_mappings', 'department')
                    ->where(fn ($query) => $query->where('clinician_tier', $tier))
                    ->ignore($mappingId),
            ],
        ];
    }
}
