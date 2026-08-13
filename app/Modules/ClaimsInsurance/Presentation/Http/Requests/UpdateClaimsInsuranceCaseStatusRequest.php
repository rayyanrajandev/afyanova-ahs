<?php

namespace App\Modules\ClaimsInsurance\Presentation\Http\Requests;

use App\Modules\ClaimsInsurance\Domain\ValueObjects\ClaimsInsuranceCaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClaimsInsuranceCaseStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(ClaimsInsuranceCaseStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,rejected,partial,cancelled'],
            'decisionReason' => ['nullable', 'string', 'max:5000', 'required_if:status,rejected,partial'],
            'submittedAt' => ['nullable', 'date', 'required_if:status,submitted'],
            'adjudicatedAt' => ['nullable', 'date', 'required_if:status,approved,rejected,partial'],
            'approvedAmount' => ['nullable', 'numeric', 'min:0', 'required_if:status,approved,partial'],
            'rejectedAmount' => ['nullable', 'numeric', 'min:0', 'required_if:status,rejected,partial'],
        ];
    }
}
