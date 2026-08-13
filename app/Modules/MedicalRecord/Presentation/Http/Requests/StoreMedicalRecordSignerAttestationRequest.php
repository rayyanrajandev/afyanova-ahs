<?php

namespace App\Modules\MedicalRecord\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMedicalRecordSignerAttestationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('medical.records.read') ?? false)
            && ($this->user()?->can('medical.records.attest') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attestationNote' => ['required', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('attestationNote')) {
            return;
        }

        $this->merge([
            'attestationNote' => trim((string) $this->input('attestationNote')),
        ]);
    }
}
