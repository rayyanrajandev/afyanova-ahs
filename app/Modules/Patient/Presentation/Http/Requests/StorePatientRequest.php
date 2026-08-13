<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patients.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:100'],
            'middleName' => ['nullable', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'gender' => ['required', Rule::in(['male', 'female', 'other', 'unknown'])],
            'dateOfBirth' => ['required', 'date', 'before:today'],
            'phone' => ['required', 'string', 'min:7', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'nationalId' => ['nullable', 'string', 'max:50'],
            'countryCode' => ['required', 'string', 'size:2'],
            'region' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'addressLine' => ['required', 'string', 'max:255'],
            'nextOfKinName' => ['nullable', 'string', 'max:150'],
            'nextOfKinPhone' => ['nullable', 'string', 'max:30'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'reason' => ['prohibited'],
            'patientNumber' => ['prohibited'],
        ];
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
