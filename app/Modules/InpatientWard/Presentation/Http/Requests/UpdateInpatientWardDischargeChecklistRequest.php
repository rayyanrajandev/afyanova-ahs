<?php

namespace App\Modules\InpatientWard\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInpatientWardDischargeChecklistRequest extends FormRequest
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
            'clinicalSummaryCompleted' => ['sometimes', 'boolean'],
            'medicationReconciliationCompleted' => ['sometimes', 'boolean'],
            'followUpPlanCompleted' => ['sometimes', 'boolean'],
            'patientEducationCompleted' => ['sometimes', 'boolean'],
            'transportArranged' => ['sometimes', 'boolean'],
            'billingCleared' => ['sometimes', 'boolean'],
            'documentationCompleted' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}

