<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowMedicalRecordVersionDiffRequest extends FormRequest
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
            'againstVersionId' => ['nullable', 'uuid'],
        ];
    }
}
