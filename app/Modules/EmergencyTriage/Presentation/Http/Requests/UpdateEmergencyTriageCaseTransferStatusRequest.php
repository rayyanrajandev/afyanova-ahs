<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Requests;

use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseTransferStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmergencyTriageCaseTransferStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(EmergencyTriageCaseTransferStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled,rejected'],
            'clinicalHandoffNotes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
