<?php

namespace App\Modules\ClinicalProcedure\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateClinicalProcedureOrderRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'orderedAt',
        'clinicalProcedureCatalogItemId',
        'procedureCode',
        'procedureSetting',
        'clinicalIndication',
        'scheduledFor',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('clinical-procedure.orders.update') ?? false;
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
            'clinicalProcedureCatalogItemId' => ['sometimes', 'nullable', 'uuid'],
            'procedureCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'procedureSetting' => ['sometimes', Rule::in(['outpatient', 'inpatient', 'bedside', 'emergency', 'other'])],
            'procedureDescription' => ['prohibited'],
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
