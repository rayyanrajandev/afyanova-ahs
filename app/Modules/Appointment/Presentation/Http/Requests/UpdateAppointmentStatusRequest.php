<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('appointment.check-in') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(AppointmentStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled,no_show'],
        ];
    }
}
