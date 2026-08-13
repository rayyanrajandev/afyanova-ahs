<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Requests;

use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferPriority;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferStatus;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmergencyTriageCaseTransferRequest extends FormRequest
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
            'transferType' => ['required', Rule::in(EmergencyTriageCaseTransferType::values())],
            'priority' => ['required', Rule::in(EmergencyTriageCaseTransferPriority::values())],
            'sourceLocation' => ['nullable', 'string', 'max:180'],
            'destinationLocation' => ['required', 'string', 'max:180'],
            'destinationFacilityName' => ['nullable', 'string', 'max:180'],
            'acceptingClinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'requestedAt' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(EmergencyTriageCaseTransferStatus::values())],
            'statusReason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled,rejected'],
            'clinicalHandoffNotes' => ['nullable', 'string', 'max:5000'],
            'transportMode' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
