<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use App\Support\FinancialCoverage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('appointments.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'sourceAdmissionId' => ['nullable', 'uuid', 'exists:admissions,id'],
            'clinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'department' => ['nullable', 'string', 'max:100'],
            'scheduledAt' => ['required', 'date', 'after_or_equal:now'],
            'durationMinutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'appointmentType' => ['nullable', \Illuminate\Validation\Rule::in(['scheduled', 'walk_in', 'referral'])],
            'financialClass' => ['nullable', Rule::in(FinancialCoverage::values())],
            'billingPayerContractId' => ['nullable', 'uuid', 'exists:billing_payer_contracts,id'],
            'coverageReference' => ['nullable', 'string', 'max:160'],
            'coverageNotes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
