<?php

namespace App\Modules\ClinicalProcedure\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClinicalProcedureOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinical-procedure.order') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
            'admissionId' => ['nullable', 'uuid'],
            'appointmentId' => ['nullable', 'uuid'],
            'entryMode' => ['nullable', Rule::in(['draft', 'active'])],
            'orderSessionId' => ['nullable', 'uuid'],
            'serviceRequestId' => ['nullable', 'uuid'],
            'replacesOrderId' => ['nullable', 'uuid', 'prohibits:addOnToOrderId'],
            'addOnToOrderId' => ['nullable', 'uuid', 'prohibits:replacesOrderId'],
            'orderedByUserId' => ['nullable', 'integer', 'exists:users,id'],
            'orderedAt' => ['nullable', 'date'],
            'clinicalProcedureCatalogItemId' => ['nullable', 'uuid'],
            'procedureCode' => ['nullable', 'string', 'max:100', 'required_without:clinicalProcedureCatalogItemId'],
            'procedureSetting' => ['required', Rule::in(['outpatient', 'inpatient', 'bedside', 'emergency', 'other'])],
            'procedureDescription' => ['nullable', 'string', 'max:255'],
            'clinicalIndication' => ['nullable', 'string', 'max:2000'],
            'scheduledFor' => ['nullable', 'date'],
        ];
    }
}
