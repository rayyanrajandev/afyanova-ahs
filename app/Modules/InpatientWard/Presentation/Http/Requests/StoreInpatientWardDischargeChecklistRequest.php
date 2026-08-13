<?php

namespace App\Modules\InpatientWard\Presentation\Http\Requests;

use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardDischargeChecklistStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInpatientWardDischargeChecklistRequest extends FormRequest
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
            'admissionId' => ['required', 'uuid'],
            'status' => ['sometimes', 'required', Rule::in(InpatientWardDischargeChecklistStatus::values())],
            'statusReason' => ['nullable', 'string', 'max:500', 'required_if:status,blocked'],
            'clinicalSummaryCompleted' => ['sometimes', 'boolean'],
            'medicationReconciliationCompleted' => ['sometimes', 'boolean'],
            'followUpPlanCompleted' => ['sometimes', 'boolean'],
            'patientEducationCompleted' => ['sometimes', 'boolean'],
            'transportArranged' => ['sometimes', 'boolean'],
            'billingCleared' => ['sometimes', 'boolean'],
            'documentationCompleted' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
