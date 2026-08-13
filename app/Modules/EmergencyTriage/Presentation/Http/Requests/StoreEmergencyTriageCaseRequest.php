<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmergencyTriageCaseRequest extends FormRequest
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
            'patientId' => ['required', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'assignedClinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'arrivalAt' => ['required', 'date'],
            'triageLevel' => ['required', Rule::in(['red', 'yellow', 'green'])],
            'chiefComplaint' => ['required', 'string', 'max:255'],
            'vitalsSummary' => ['nullable', 'string', 'max:2000'],
            'triagedAt' => ['nullable', 'date', 'after_or_equal:arrivalAt'],
            'dispositionNotes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
