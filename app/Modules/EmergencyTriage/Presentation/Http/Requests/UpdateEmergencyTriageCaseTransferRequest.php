<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Requests;

use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferPriority;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmergencyTriageCaseTransferRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'transferType',
        'priority',
        'sourceLocation',
        'destinationLocation',
        'destinationFacilityName',
        'acceptingClinicianUserId',
        'requestedAt',
        'clinicalHandoffNotes',
        'transportMode',
        'metadata',
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
            'transferType' => ['sometimes', Rule::in(EmergencyTriageCaseTransferType::values())],
            'priority' => ['sometimes', Rule::in(EmergencyTriageCaseTransferPriority::values())],
            'sourceLocation' => ['nullable', 'string', 'max:180'],
            'destinationLocation' => ['sometimes', 'string', 'max:180'],
            'destinationFacilityName' => ['nullable', 'string', 'max:180'],
            'acceptingClinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'requestedAt' => ['sometimes', 'date'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'clinicalHandoffNotes' => ['nullable', 'string', 'max:5000'],
            'transportMode' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requestedKeys = array_keys($this->all());
            $hasAllowedField = count(array_intersect($requestedKeys, self::ALLOWED_FIELDS)) > 0;

            if (! $hasAllowedField) {
                $validator->errors()->add('payload', 'At least one updatable transfer field is required.');
            }
        });
    }
}
