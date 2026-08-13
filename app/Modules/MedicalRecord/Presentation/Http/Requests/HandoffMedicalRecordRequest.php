<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandoffMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'targetUserId' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
