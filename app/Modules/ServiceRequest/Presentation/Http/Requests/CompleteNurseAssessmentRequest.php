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
            // `present`, not `required` (2026-08-13, Volume 3.8 Phase 5): a
            // nurse who reviews a patient and decides no downstream orders
            // are needed must still be able to complete the assessment — an
            // empty `items` array is a valid, real outcome, not a missing
            // field. This is also what makes the never-implemented
            // `nursing/tasks/{id}/complete` route/method (found dead —
            // no controller method, never called by the frontend) properly
            // redundant rather than a missing feature: assessing with zero
            // items already covers what that route was meant to do.
            'items' => ['present', 'array', 'max:50'],
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
            'items.present' => 'The items field must be present, even if empty.',
            'items.*.serviceType.in' => 'Invalid service type. Must be one of: laboratory, pharmacy, radiology, clinical_procedure.',
        ];
    }
}
