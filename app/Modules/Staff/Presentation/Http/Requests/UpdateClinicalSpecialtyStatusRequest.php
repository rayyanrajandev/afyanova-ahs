<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use App\Modules\Staff\Domain\ValueObjects\ClinicalSpecialtyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicalSpecialtyStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(ClinicalSpecialtyStatus::values())],
            'reason' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('status') === ClinicalSpecialtyStatus::INACTIVE->value),
            ],
        ];
    }
}

