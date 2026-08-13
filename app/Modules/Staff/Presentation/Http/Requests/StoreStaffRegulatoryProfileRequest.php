<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use App\Modules\Staff\Domain\ValueObjects\StaffGoodStandingStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffPracticeAuthorityLevel;
use App\Modules\Staff\Domain\ValueObjects\StaffRegulatorCode;
use App\Modules\Staff\Domain\ValueObjects\StaffSupervisionLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRegulatoryProfileRequest extends FormRequest
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
            'primaryRegulatorCode' => ['required', 'string', Rule::in(StaffRegulatorCode::values())],
            'cadreCode' => ['required', 'string', 'max:100'],
            'professionalTitle' => ['required', 'string', 'max:150'],
            'registrationType' => ['required', 'string', 'max:80'],
            'practiceAuthorityLevel' => ['required', 'string', Rule::in(StaffPracticeAuthorityLevel::values())],
            'supervisionLevel' => ['required', 'string', Rule::in(StaffSupervisionLevel::values())],
            'goodStandingStatus' => ['required', 'string', Rule::in(StaffGoodStandingStatus::values())],
            'goodStandingCheckedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
