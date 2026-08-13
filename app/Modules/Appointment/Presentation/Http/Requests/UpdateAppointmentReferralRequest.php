<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralPriority;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralStatus;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAppointmentReferralRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'referralType',
        'priority',
        'targetDepartment',
        'targetFacilityId',
        'targetFacilityCode',
        'targetFacilityName',
        'targetClinicianUserId',
        'referralReason',
        'clinicalNotes',
        'handoffNotes',
        'requestedAt',
        'status',
        'statusReason',
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
            'referralType' => ['sometimes', Rule::in(AppointmentReferralType::values())],
            'priority' => ['sometimes', Rule::in(AppointmentReferralPriority::values())],
            'targetDepartment' => ['nullable', 'string', 'max:120'],
            'targetFacilityId' => ['nullable', 'uuid', 'exists:facilities,id'],
            'targetFacilityCode' => ['nullable', 'string', 'max:30'],
            'targetFacilityName' => ['nullable', 'string', 'max:180'],
            'targetClinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'referralReason' => ['nullable', 'string', 'max:255'],
            'clinicalNotes' => ['nullable', 'string', 'max:5000'],
            'handoffNotes' => ['nullable', 'string', 'max:5000'],
            'requestedAt' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(AppointmentReferralStatus::values())],
            'statusReason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled,rejected'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requestedKeys = array_keys($this->all());
            $hasAllowedField = count(array_intersect($requestedKeys, self::ALLOWED_FIELDS)) > 0;

            if (! $hasAllowedField) {
                $validator->errors()->add('payload', 'At least one updatable referral field is required.');
            }
        });
    }
}
