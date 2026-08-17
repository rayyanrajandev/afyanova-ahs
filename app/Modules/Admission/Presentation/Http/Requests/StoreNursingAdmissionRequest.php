<?php

namespace App\Modules\Admission\Presentation\Http\Requests;

use App\Support\FinancialCoverage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nursing workspace admission request (POST /api/v1/nursing/admissions).
 *
 * Mirrors StoreAdmissionRequest's fields but is workspace-scoped: it requires
 * the open encounter id (so the endpoint can link the admission to it and
 * upgrade the encounter to `inpatient`), and omits the generic
 * patient/appointment-only concerns the legacy route forced on the caller.
 */
class StoreNursingAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if (
            $user->can('admissions.create')
            || $user->can('inpatient.ward.create')
            || $user->can('emergency.triage.create')
            || $user->can('appointments.record-triage')
        ) {
            return true;
        }

        if (method_exists($user, 'hasRole') && ($user->hasRole('nurse') || $user->hasRole('doctor') || $user->hasRole('clinician') || $user->hasRole('facility_admin'))) {
            return true;
        }

        if (isset($user->facility_role) && in_array($user->facility_role, ['nurse', 'doctor', 'clinician', 'facility_admin', 'super_admin'], true)) {
            return true;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'encounterId' => ['required', 'uuid'],
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
