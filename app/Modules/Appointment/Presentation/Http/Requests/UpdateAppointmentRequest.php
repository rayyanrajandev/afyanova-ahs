<?php

namespace App\Modules\Appointment\Presentation\Http\Requests;

use App\Support\FinancialCoverage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAppointmentRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
            'patientId',
            'clinicianUserId',
            'department',
        'scheduledAt',
        'durationMinutes',
        'reason',
        'notes',
        'financialClass',
        'billingPayerContractId',
        'coverageReference',
        'coverageNotes',
    ];

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
            'patientId' => ['sometimes', 'uuid'],
            'sourceAdmissionId' => ['prohibited'],
            'clinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'department' => ['nullable', 'string', 'max:100'],
            'scheduledAt' => ['sometimes', 'date'],
            'durationMinutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'financialClass' => ['sometimes', Rule::in(FinancialCoverage::values())],
            'billingPayerContractId' => ['nullable', 'uuid', 'exists:billing_payer_contracts,id'],
            'coverageReference' => ['nullable', 'string', 'max:160'],
            'coverageNotes' => ['nullable', 'string', 'max:4000'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            // Triage fields must go through PATCH /appointments/{id}/triage, which is
            // gated by appointments.record-triage rather than this endpoint's
            // appointments.update — see reports/patient-arrival-checkin-audit.md §7.
            'triageVitalsSummary' => ['prohibited'],
            'triageNotes' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requestedKeys = array_keys($this->all());
            $hasAllowedField = count(array_intersect($requestedKeys, self::ALLOWED_FIELDS)) > 0;

            if (! $hasAllowedField) {
                $validator->errors()->add('payload', 'At least one updatable field is required.');
            }
        });
    }
}
