<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Requests;

use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmergencyTriageCaseStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(EmergencyTriageCaseStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled'],
            'dispositionNotes' => ['nullable', 'string', 'max:5000', 'required_if:status,admitted,discharged'],
            'bedResourceId' => ['nullable', 'uuid', 'required_if:status,admitted'],
        ];
    }
}
