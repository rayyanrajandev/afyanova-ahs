<?php

namespace App\Modules\ClinicalProcedure\Presentation\Http\Requests;

use App\Modules\ClinicalProcedure\Domain\ValueObjects\ClinicalProcedureOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicalProcedureOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinical-procedure.perform') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ClinicalProcedureOrderStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled'],
            'reportSummary' => ['nullable', 'string', 'max:5000', 'required_if:status,completed'],
        ];
    }
}
