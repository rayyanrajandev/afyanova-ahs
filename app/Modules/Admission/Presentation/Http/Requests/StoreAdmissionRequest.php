<?php

namespace App\Modules\Admission\Presentation\Http\Requests;

use App\Support\FinancialCoverage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admissions.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'attendingClinicianUserId' => ['nullable', 'integer', 'exists:users,id'],
            'bedResourceId' => ['nullable', 'uuid'],
            'ward' => ['nullable', 'string', 'max:120', 'required_with:bed'],
            'bed' => ['nullable', 'string', 'max:40', 'required_with:ward'],
            'admittedAt' => ['required', 'date', 'before_or_equal:now'],
            'admissionReason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'financialClass' => ['nullable', Rule::in(FinancialCoverage::values())],
            'billingPayerContractId' => ['nullable', 'uuid', 'exists:billing_payer_contracts,id'],
            'coverageReference' => ['nullable', 'string', 'max:160'],
            'coverageNotes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
