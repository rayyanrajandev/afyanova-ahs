<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('staff.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'userId' => ['required', 'integer', 'exists:users,id'],
            'departmentId' => ['required', 'string', 'exists:departments,id'],
            'department' => ['sometimes', 'string', 'max:100'],
            'jobTitle' => ['required', 'string', 'max:150'],
            'professionalLicenseNumber' => ['nullable', 'string', 'max:100'],
            'licenseType' => ['nullable', 'string', 'max:100'],
            'phoneExtension' => ['nullable', 'string', 'max:20'],
            'employmentType' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'locum'])],
        ];
    }
}
