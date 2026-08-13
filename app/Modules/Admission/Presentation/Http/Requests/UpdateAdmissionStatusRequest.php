<?php

namespace App\Modules\Admission\Presentation\Http\Requests;

use App\Modules\Admission\Domain\ValueObjects\AdmissionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admissions.update-status') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // receivingWard/receivingBed are only required for a transfer when
        // receivingBedResourceId isn't provided — a V2 caller using the
        // real bed picker sends only the resource id, not the free-text
        // pair; a legacy caller still using free text needs both.
        $usingBedResource = trim((string) $this->input('receivingBedResourceId', '')) !== '';

        return [
            'status' => ['required', Rule::in(AdmissionStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,discharged,transferred,cancelled'],
            'dischargeDestination' => ['nullable', 'string', 'max:120', 'required_if:status,discharged'],
            'followUpPlan' => ['nullable', 'string', 'max:2000'],
            'receivingBedResourceId' => ['nullable', 'uuid'],
            'receivingWard' => [
                'nullable', 'string', 'max:120',
                Rule::requiredIf(fn (): bool => ! $usingBedResource && $this->input('status') === AdmissionStatus::TRANSFERRED->value),
            ],
            'receivingBed' => [
                'nullable', 'string', 'max:40',
                Rule::requiredIf(fn (): bool => ! $usingBedResource && $this->input('status') === AdmissionStatus::TRANSFERRED->value),
            ],
        ];
    }
}
