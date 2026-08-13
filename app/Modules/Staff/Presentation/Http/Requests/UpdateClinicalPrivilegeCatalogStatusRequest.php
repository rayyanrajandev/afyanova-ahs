<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use App\Modules\Staff\Domain\ValueObjects\ClinicalPrivilegeCatalogStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicalPrivilegeCatalogStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(ClinicalPrivilegeCatalogStatus::values())],
            'reason' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('status') === ClinicalPrivilegeCatalogStatus::INACTIVE->value),
            ],
        ];
    }
}
