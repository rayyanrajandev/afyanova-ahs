<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalLicenseStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalRegistrationStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffRegulatorCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffProfessionalRegistrationRequest extends FormRequest
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
            'regulatorCode' => ['required', 'string', Rule::in(StaffRegulatorCode::values())],
            'registrationCategory' => ['required', 'string', 'max:80'],
            'registrationNumber' => ['required', 'string', 'max:120'],
            'licenseNumber' => ['nullable', 'string', 'max:120'],
            'registrationStatus' => ['required', 'string', Rule::in(StaffProfessionalRegistrationStatus::values())],
            'licenseStatus' => ['required', 'string', Rule::in(StaffProfessionalLicenseStatus::values())],
            'issuedAt' => ['nullable', 'date'],
            'expiresAt' => ['nullable', 'date', 'after_or_equal:issuedAt'],
            'renewalDueAt' => ['nullable', 'date', 'after_or_equal:issuedAt'],
            'cpdCycleStartAt' => ['nullable', 'date'],
            'cpdCycleEndAt' => ['nullable', 'date', 'after_or_equal:cpdCycleStartAt'],
            'cpdPointsRequired' => ['nullable', 'integer', 'min:0'],
            'cpdPointsEarned' => ['nullable', 'integer', 'min:0'],
            'sourceDocumentId' => ['nullable', 'uuid', 'exists:staff_documents,id'],
            'sourceSystem' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'verificationStatus' => ['prohibited'],
            'verificationReason' => ['prohibited'],
            'verificationNotes' => ['prohibited'],
        ];
    }
}
