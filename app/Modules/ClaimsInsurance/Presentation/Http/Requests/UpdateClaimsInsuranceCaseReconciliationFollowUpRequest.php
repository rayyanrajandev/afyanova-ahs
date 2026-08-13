<?php

namespace App\Modules\ClaimsInsurance\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClaimsInsuranceCaseReconciliationFollowUpRequest extends FormRequest
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
            'followUpStatus' => ['required', Rule::in(['pending', 'in_progress', 'resolved', 'waived'])],
            'followUpDueAt' => ['nullable', 'date'],
            'followUpNote' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
