<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncStaffProfileSpecialtiesRequest extends FormRequest
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
            'specialtyAssignments' => ['required', 'array'],
            'specialtyAssignments.*.specialtyId' => ['required', 'string', 'uuid', 'distinct', 'exists:clinical_specialties,id'],
            'specialtyAssignments.*.isPrimary' => ['nullable', 'boolean'],
        ];
    }
}

