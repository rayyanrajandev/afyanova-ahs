<?php

namespace App\Modules\Reception\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same permission the queue view itself is gated on
        // (GetReceptionQueueUseCase / ReceptionController::queue) — reordering
        // is a queue-management action, not a distinct capability of its own.
        return $this->user()?->can('appointment.check-in') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'appointmentIds' => ['required', 'array', 'min:1'],
            'appointmentIds.*' => ['string', 'distinct'],
        ];
    }
}
