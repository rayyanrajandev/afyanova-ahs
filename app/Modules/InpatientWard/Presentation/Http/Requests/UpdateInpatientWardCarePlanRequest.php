<?php

namespace App\Modules\InpatientWard\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInpatientWardCarePlanRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'planText' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'goals' => ['sometimes', 'nullable', 'array'],
            'goals.*' => ['nullable', 'string', 'max:1000'],
            'interventions' => ['sometimes', 'nullable', 'array'],
            'interventions.*' => ['nullable', 'string', 'max:1000'],
            'targetDischargeAt' => ['sometimes', 'nullable', 'date'],
            'reviewDueAt' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}

