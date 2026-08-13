<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartAppointmentConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('appointments.start-consultation') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'forceTakeover' => ['sometimes', 'boolean'],
            'takeoverReason' => ['nullable', 'string', 'max:255', 'required_if:forceTakeover,true'],
        ];
    }
}
