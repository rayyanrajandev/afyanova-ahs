<?php

namespace App\Modules\Reception\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelQueueItemRequest extends FormRequest
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
            // Required — UpdateAppointmentStatusUseCase/AppointmentStatus require
            // a reason for cancelled/no_show transitions (audit trail).
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
