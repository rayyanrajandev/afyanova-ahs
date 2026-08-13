<?php

namespace App\Modules\InpatientWard\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInpatientWardRoundNoteRequest extends FormRequest
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
            'roundedAt' => ['nullable', 'date'],
            'shiftLabel' => ['nullable', 'string', Rule::in(['day', 'evening', 'night'])],
            'roundNote' => ['required', 'string', 'max:5000'],
            'carePlan' => ['nullable', 'string', 'max:5000'],
            'handoffNotes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
