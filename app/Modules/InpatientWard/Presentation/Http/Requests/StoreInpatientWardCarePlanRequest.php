<?php

namespace App\Modules\InpatientWard\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInpatientWardCarePlanRequest extends FormRequest
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
            'admissionId' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:180'],
            'planText' => ['nullable', 'string', 'max:10000'],
            'goals' => ['nullable', 'array'],
            'goals.*' => ['nullable', 'string', 'max:1000'],
            'interventions' => ['nullable', 'array'],
            'interventions.*' => ['nullable', 'string', 'max:1000'],
            'targetDischargeAt' => ['nullable', 'date'],
            'reviewDueAt' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

