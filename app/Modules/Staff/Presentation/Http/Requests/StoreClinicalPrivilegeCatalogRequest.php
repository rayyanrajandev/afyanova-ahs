<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClinicalPrivilegeCatalogRequest extends FormRequest
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
            'specialtyId' => ['required', 'uuid', 'exists:clinical_specialties,id'],
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cadreCode' => ['nullable', 'string', 'max:80'],
            'facilityType' => ['nullable', 'string', 'max:80'],
        ];
    }
}
