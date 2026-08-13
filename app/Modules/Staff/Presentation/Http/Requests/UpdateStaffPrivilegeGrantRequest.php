<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStaffPrivilegeGrantRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'facilityId',
        'privilegeCatalogId',
        'specialtyId',
        'privilegeCode',
        'privilegeName',
        'scopeNotes',
        'grantedAt',
        'reviewDueAt',
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
            'facilityId' => ['sometimes', 'uuid', 'exists:facilities,id'],
            'privilegeCatalogId' => ['sometimes', 'uuid', 'exists:clinical_privilege_catalogs,id'],
            'specialtyId' => ['sometimes', 'uuid', 'exists:clinical_specialties,id'],
            'privilegeCode' => ['sometimes', 'string', 'max:60'],
            'privilegeName' => ['sometimes', 'string', 'max:180'],
            'scopeNotes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'grantedAt' => ['sometimes', 'date'],
            'reviewDueAt' => ['sometimes', 'nullable', 'date', 'after_or_equal:grantedAt'],
            'status' => ['prohibited'],
            'reason' => ['prohibited'],
            'statusReason' => ['prohibited'],
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
