<?php

namespace App\Modules\Revenue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Money leaves a named drawer, so the refund shows up in that
            // session's expected cash instead of vanishing into the facility.
            'paidFromSessionId' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
