<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalLicenseStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalRegistrationStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffRegulatorCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStaffProfessionalRegistrationRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'regulatorCode',
        'registrationCategory',
        'registrationNumber',
        'licenseNumber',
        'registrationStatus',
        'licenseStatus',
        'issuedAt',
        'expiresAt',
        'renewalDueAt',
        'cpdCycleStartAt',
        'cpdCycleEndAt',
        'cpdPointsRequired',
        'cpdPointsEarned',
        'sourceDocumentId',
        'sourceSystem',
        'notes',
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
            'regulatorCode' => ['sometimes', 'string', Rule::in(StaffRegulatorCode::values())],
            'registrationCategory' => ['sometimes', 'string', 'max:80'],
            'registrationNumber' => ['sometimes', 'string', 'max:120'],
            'licenseNumber' => ['sometimes', 'nullable', 'string', 'max:120'],
            'registrationStatus' => ['sometimes', 'string', Rule::in(StaffProfessionalRegistrationStatus::values())],
            'licenseStatus' => ['sometimes', 'string', Rule::in(StaffProfessionalLicenseStatus::values())],
            'issuedAt' => ['sometimes', 'nullable', 'date'],
            'expiresAt' => ['sometimes', 'nullable', 'date', 'after_or_equal:issuedAt'],
            'renewalDueAt' => ['sometimes', 'nullable', 'date', 'after_or_equal:issuedAt'],
            'cpdCycleStartAt' => ['sometimes', 'nullable', 'date'],
            'cpdCycleEndAt' => ['sometimes', 'nullable', 'date', 'after_or_equal:cpdCycleStartAt'],
            'cpdPointsRequired' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cpdPointsEarned' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sourceDocumentId' => ['sometimes', 'nullable', 'uuid', 'exists:staff_documents,id'],
            'sourceSystem' => ['sometimes', 'nullable', 'string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'verificationStatus' => ['prohibited'],
            'verificationReason' => ['prohibited'],
            'verificationNotes' => ['prohibited'],
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
