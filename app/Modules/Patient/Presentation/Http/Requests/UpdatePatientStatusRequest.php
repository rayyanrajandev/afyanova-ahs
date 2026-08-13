<?php

namespace App\Modules\Patient\Presentation\Http\Requests;

use App\Modules\Patient\Domain\ValueObjects\PatientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePatientStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patients.update-status') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(PatientStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,inactive'],
        ];
    }
}
