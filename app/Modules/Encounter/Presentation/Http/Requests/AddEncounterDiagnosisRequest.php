<?php

namespace App\Modules\Encounter\Presentation\Http\Requests;

use App\Modules\Encounter\Domain\ValueObjects\EncounterDiagnosisType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddEncounterDiagnosisRequest extends FormRequest
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
            'diagnosisCode' => ['required', 'string', 'max:20'],
            'diagnosisDescription' => ['nullable', 'string', 'max:255'],
            'diagnosisType' => ['nullable', 'string', Rule::in(EncounterDiagnosisType::values())],
        ];
    }
}
