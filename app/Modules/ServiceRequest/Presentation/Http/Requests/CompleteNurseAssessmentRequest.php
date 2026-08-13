<?php

namespace App\Modules\ServiceRequest\Presentation\Http\Requests;

use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteNurseAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('service.requests.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clinicalNote' => ['required', 'string', 'max:5000'],
            'items' => ['required', 'array', 'max:50'],
            'items.*.catalogItemId' => ['nullable', 'uuid'],
            'items.*.itemName' => ['required', 'string', 'max:255'],
            'items.*.itemCode' => ['nullable', 'string', 'max:50'],
            'items.*.serviceType' => ['required', Rule::in(ServiceRequestServiceType::values())],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'clinicalNote.required' => 'A clinical note describing the patient\'s condition is required.',
            'items.required' => 'At least one service item is required.',
            'items.*.serviceType.in' => 'Invalid service type. Must be one of: laboratory, pharmacy, radiology, clinical_procedure.',
        ];
    }
}
