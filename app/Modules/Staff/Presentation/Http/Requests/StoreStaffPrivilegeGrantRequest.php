<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffPrivilegeGrantRequest extends FormRequest
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
            'facilityId' => ['required', 'uuid', 'exists:facilities,id'],
            'privilegeCatalogId' => ['nullable', 'uuid', 'exists:clinical_privilege_catalogs,id'],
            'specialtyId' => ['required_without:privilegeCatalogId', 'nullable', 'uuid', 'exists:clinical_specialties,id'],
            'privilegeCode' => ['required_without:privilegeCatalogId', 'nullable', 'string', 'max:60'],
            'privilegeName' => ['required_without:privilegeCatalogId', 'nullable', 'string', 'max:180'],
            'scopeNotes' => ['nullable', 'string', 'max:2000'],
            'grantedAt' => ['required', 'date'],
            'reviewDueAt' => ['nullable', 'date', 'after_or_equal:grantedAt'],
        ];
    }
}
