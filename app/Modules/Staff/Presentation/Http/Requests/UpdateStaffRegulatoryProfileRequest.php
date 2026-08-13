<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use App\Modules\Staff\Domain\ValueObjects\StaffGoodStandingStatus;
use App\Modules\Staff\Domain\ValueObjects\StaffPracticeAuthorityLevel;
use App\Modules\Staff\Domain\ValueObjects\StaffRegulatorCode;
use App\Modules\Staff\Domain\ValueObjects\StaffSupervisionLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStaffRegulatoryProfileRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'primaryRegulatorCode',
        'cadreCode',
        'professionalTitle',
        'registrationType',
        'practiceAuthorityLevel',
        'supervisionLevel',
        'goodStandingStatus',
        'goodStandingCheckedAt',
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
            'primaryRegulatorCode' => ['sometimes', 'string', Rule::in(StaffRegulatorCode::values())],
            'cadreCode' => ['sometimes', 'string', 'max:100'],
            'professionalTitle' => ['sometimes', 'string', 'max:150'],
            'registrationType' => ['sometimes', 'string', 'max:80'],
            'practiceAuthorityLevel' => ['sometimes', 'string', Rule::in(StaffPracticeAuthorityLevel::values())],
            'supervisionLevel' => ['sometimes', 'string', Rule::in(StaffSupervisionLevel::values())],
            'goodStandingStatus' => ['sometimes', 'string', Rule::in(StaffGoodStandingStatus::values())],
            'goodStandingCheckedAt' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
