<?php

namespace App\Modules\Billing\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyPatientInsuranceRecordRequest extends FormRequest
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
            'verificationStatus' => ['required', Rule::in(['verified', 'failed', 'expired', 'unverified'])],
            'verificationSource' => ['nullable', 'string', 'max:80'],
            'verificationReference' => ['nullable', 'string', 'max:160'],
        ];
    }
}
