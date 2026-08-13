<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmergencyTriageCaseRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'patientId',
        'admissionId',
        'appointmentId',
        'assignedClinicianUserId',
        'arrivalAt',
        'triageLevel',
        'chiefComplaint',
        'vitalsSummary',
        'triagedAt',
        'dispositionNotes',
    ];

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
            'patientId' => ['sometimes', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'assignedClinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'arrivalAt' => ['sometimes', 'date'],
            'triageLevel' => ['sometimes', Rule::in(['red', 'yellow', 'green'])],
            'chiefComplaint' => ['sometimes', 'string', 'max:255'],
            'vitalsSummary' => ['nullable', 'string', 'max:2000'],
            'triagedAt' => ['nullable', 'date'],
            'dispositionNotes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requestedKeys = array_keys($this->all());
            $hasAllowedField = count(array_intersect($requestedKeys, self::ALLOWED_FIELDS)) > 0;

            if (! $hasAllowedField) {
                $validator->errors()->add('payload', 'At least one updatable field is required.');
            }
        });
    }
}
