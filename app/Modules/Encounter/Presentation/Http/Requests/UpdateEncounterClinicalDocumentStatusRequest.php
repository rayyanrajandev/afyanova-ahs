<?php

namespace App\Modules\Encounter\Presentation\Http\Requests;

use App\Modules\Encounter\Domain\ValueObjects\EncounterClinicalDocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEncounterClinicalDocumentStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(EncounterClinicalDocumentStatus::values())],
            'reason' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('status') === EncounterClinicalDocumentStatus::ARCHIVED->value),
            ],
        ];
    }
}
