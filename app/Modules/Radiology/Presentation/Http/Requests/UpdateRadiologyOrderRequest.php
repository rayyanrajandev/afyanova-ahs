<?php

namespace App\Modules\Radiology\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRadiologyOrderRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'orderedAt',
        'radiologyProcedureCatalogItemId',
        'procedureCode',
        'modality',
        'clinicalIndication',
        'scheduledFor',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('radiology.orders.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['prohibited'],
            'admissionId' => ['prohibited'],
            'appointmentId' => ['prohibited'],
            'orderedByUserId' => ['prohibited'],
            'orderedAt' => ['sometimes', 'date'],
            'radiologyProcedureCatalogItemId' => ['sometimes', 'nullable', 'uuid'],
            'procedureCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'modality' => ['sometimes', Rule::in(['xray', 'ultrasound', 'ct', 'mri', 'other'])],
            'studyDescription' => ['prohibited'],
            'clinicalIndication' => ['nullable', 'string', 'max:2000'],
            'scheduledFor' => ['nullable', 'date'],
            'entryMode' => ['prohibited'],
            'orderSessionId' => ['prohibited'],
            'replacesOrderId' => ['prohibited'],
            'addOnToOrderId' => ['prohibited'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'reason' => ['prohibited'],
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
