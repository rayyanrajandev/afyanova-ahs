<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentProviderWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('appointments.manage-provider-session') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                AppointmentStatus::WAITING_TRIAGE->value,
                AppointmentStatus::WAITING_PROVIDER->value,
                AppointmentStatus::COMPLETED->value,
            ])],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,waiting_triage'],
        ];
    }
}
