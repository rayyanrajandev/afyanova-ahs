<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordAppointmentTriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->can('appointments.record-triage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'triageVitalsSummary' => ['required', 'string', 'max:4000'],
            'triageNotes' => ['nullable', 'string', 'max:4000'],
            'triageCategory' => ['nullable', Rule::in(['P1', 'P2', 'P3', 'P4', 'P5'])],
            'department' => ['nullable', 'string', 'max:100'],
            'clinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
