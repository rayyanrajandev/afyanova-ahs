<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptMedicalRecordHandoffRequest extends FormRequest
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
            'action' => ['required', 'string', 'in:accept,decline'],
        ];
    }
}
