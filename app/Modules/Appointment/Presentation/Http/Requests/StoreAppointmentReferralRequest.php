<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralPriority;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralStatus;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentReferralRequest extends FormRequest
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
            'referralType' => ['required', Rule::in(AppointmentReferralType::values())],
            'priority' => ['required', Rule::in(AppointmentReferralPriority::values())],
            'targetDepartment' => ['nullable', 'string', 'max:120'],
            'targetFacilityId' => ['nullable', 'uuid', 'exists:facilities,id'],
            'targetFacilityCode' => ['nullable', 'string', 'max:30'],
            'targetFacilityName' => ['nullable', 'string', 'max:180'],
            'targetClinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'referralReason' => ['nullable', 'string', 'max:255'],
            'clinicalNotes' => ['nullable', 'string', 'max:5000'],
            'handoffNotes' => ['nullable', 'string', 'max:5000'],
            'requestedAt' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(AppointmentReferralStatus::values())],
            'statusReason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled,rejected'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
