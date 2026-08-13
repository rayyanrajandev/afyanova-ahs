<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePatientRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'firstName',
        'middleName',
        'lastName',
        'gender',
        'dateOfBirth',
        'phone',
        'email',
        'nationalId',
        'countryCode',
        'region',
        'district',
        'addressLine',
        'nextOfKinName',
        'nextOfKinPhone',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('patient.demographics.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'firstName' => ['sometimes', 'string', 'max:100'],
            'middleName' => ['nullable', 'string', 'max:100'],
            'lastName' => ['sometimes', 'string', 'max:100'],
            'gender' => ['sometimes', Rule::in(['male', 'female', 'other', 'unknown'])],
            'dateOfBirth' => ['sometimes', 'date', 'before:today'],
            'phone' => ['sometimes', 'required', 'string', 'min:7', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'nationalId' => ['nullable', 'string', 'max:50'],
            'countryCode' => ['sometimes', 'string', 'size:2'],
            'region' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'addressLine' => ['nullable', 'string', 'max:255'],
            'nextOfKinName' => ['nullable', 'string', 'max:150'],
            'nextOfKinPhone' => ['nullable', 'string', 'max:30'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'reason' => ['prohibited'],
            'patientNumber' => ['prohibited'],
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

    protected function prepareForValidation(): void
    {
        if (! $this->has('countryCode')) {
            return;
        }

        $countryCode = $this->input('countryCode');

        $this->merge([
            'countryCode' => is_string($countryCode) ? strtoupper(trim($countryCode)) : $countryCode,
        ]);
    }
}
