<?php

namespace App\Modules\Admission\Presentation\Http\Requests;

use App\Support\FinancialCoverage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAdmissionRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FIELDS = [
        'patientId',
        'appointmentId',
        'attendingClinicianUserId',
        'ward',
        'bed',
        'admittedAt',
        'admissionReason',
        'notes',
        'financialClass',
        'billingPayerContractId',
        'coverageReference',
        'coverageNotes',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('admissions.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['sometimes', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'attendingClinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'ward' => ['nullable', 'string', 'max:120'],
            'bed' => ['nullable', 'string', 'max:40'],
            'admittedAt' => ['sometimes', 'date', 'before_or_equal:now'],
            'admissionReason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'financialClass' => ['sometimes', Rule::in(FinancialCoverage::values())],
            'billingPayerContractId' => ['nullable', 'uuid', 'exists:billing_payer_contracts,id'],
            'coverageReference' => ['nullable', 'string', 'max:160'],
            'coverageNotes' => ['nullable', 'string', 'max:4000'],
            'status' => ['prohibited'],
            'statusReason' => ['prohibited'],
            'reason' => ['prohibited'],
            'dischargedAt' => ['prohibited'],
            'dischargeDestination' => ['prohibited'],
            'followUpPlan' => ['prohibited'],
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
