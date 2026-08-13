<?php

namespace App\Modules\Department\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'serviceType' => ['nullable', 'string', 'max:80'],
            'isPatientFacing' => ['sometimes', 'boolean'],
            'isAppointmentable' => ['sometimes', 'boolean'],
            'managerUserId' => ['nullable', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

