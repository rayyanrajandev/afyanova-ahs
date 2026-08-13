<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseAppointmentTriageClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('appointments.record-triage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
