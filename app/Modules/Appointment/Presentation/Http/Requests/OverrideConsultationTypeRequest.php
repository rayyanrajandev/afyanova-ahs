<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use App\Modules\Appointment\Domain\ValueObjects\ConsultationClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OverrideConsultationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('appointment.reschedule') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'consultationType' => [
                'required',
                Rule::in(ConsultationClassification::values()),
            ],
            'consultationTypeOverrideReason' => [
                'required',
                'string',
                'min:5',
                'max:500',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consultationType.required'              => 'consultationType is required.',
            'consultationType.in'                    => 'consultationType must be one of: '.implode(', ', ConsultationClassification::values()).'.',
            'consultationTypeOverrideReason.required' => 'A reason is required when manually overriding the consultation type.',
            'consultationTypeOverrideReason.min'      => 'The override reason must be at least 5 characters.',
        ];
    }
}
