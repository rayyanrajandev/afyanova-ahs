<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStaffProfileRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'department',
        'departmentId',
        'jobTitle',
        'professionalLicenseNumber',
        'licenseType',
        'phoneExtension',
        'employmentType',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('staff.employment.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'userId' => ['prohibited'],
            'departmentId' => ['sometimes', 'string', 'exists:departments,id'],
            'department' => ['sometimes', 'string', 'max:100'],
            'jobTitle' => ['sometimes', 'string', 'max:150'],
            'professionalLicenseNumber' => ['nullable', 'string', 'max:100'],
            'licenseType' => ['nullable', 'string', 'max:100'],
            'phoneExtension' => ['nullable', 'string', 'max:20'],
            'employmentType' => ['sometimes', Rule::in(['full_time', 'part_time', 'contract', 'locum'])],
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
