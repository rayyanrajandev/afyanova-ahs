<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalRegistrationVerificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffProfessionalRegistrationVerificationRequest extends FormRequest
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
            'verificationStatus' => ['required', 'string', Rule::in(StaffProfessionalRegistrationVerificationStatus::values())],
            'reason' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('verificationStatus') === StaffProfessionalRegistrationVerificationStatus::REJECTED->value),
            ],
            'verificationNotes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
