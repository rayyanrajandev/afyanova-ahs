<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentReferralStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(AppointmentReferralStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled,rejected'],
            'handoffNotes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

