<?php

namespace App\Modules\Laboratory\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLaboratoryOrderRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'orderedAt',
        'labTestCatalogItemId',
        'testCode',
        'priority',
        'specimenType',
        'clinicalNotes',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('lab.order') ?? false;
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
            'labTestCatalogItemId' => ['sometimes', 'nullable', 'uuid'],
            'testCode' => ['sometimes', 'nullable', 'string', 'max:50'],
            'testName' => ['prohibited'],
            'priority' => ['sometimes', Rule::in(['routine', 'urgent', 'stat'])],
            'specimenType' => ['nullable', 'string', 'max:100'],
            'clinicalNotes' => ['nullable', 'string', 'max:2000'],
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
