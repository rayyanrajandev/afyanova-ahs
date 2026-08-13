<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately all-nullable, unlike StorePatientRequest — this is called
 * progressively as the registration form is filled in, not at submit time,
 * so a partial identity (e.g. just firstName/lastName/dateOfBirth) is an
 * expected, valid call, not an error.
 */
class CheckPatientDuplicatesRequest extends FormRequest
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
            'firstName' => ['nullable', 'string', 'max:100'],
            'lastName' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'unknown'])],
            'dateOfBirth' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:30'],
            'nationalId' => ['nullable', 'string', 'max:50'],
            'addressLine' => ['nullable', 'string', 'max:255'],
            'excludePatientId' => ['nullable', 'uuid'],
        ];
    }
}
