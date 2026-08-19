<?php

namespace App\Modules\Revenue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by every action that undoes or overrides something: reversal,
 * cancellation, variance approval.
 *
 * The reason is required rather than optional throughout. An audit trail of
 * exceptions with no explanations answers "what happened" and not "why", and
 * only the second is any use months later.
 */
class ReasonedActionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
