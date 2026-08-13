<?php

namespace App\Modules\Pharmacy\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReconcilePharmacyOrderRequest extends FormRequest
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
            'reconciliationStatus' => ['required', Rule::in(['pending', 'completed', 'exception'])],
            'reconciliationDecision' => [
                'nullable',
                Rule::in([
                    'add_to_current_list',
                    'continue_on_current_list',
                    'short_course_only',
                    'stop_from_current_list',
                    'review_later',
                ]),
            ],
            'reconciliationNote' => ['nullable', 'string', 'max:1000', 'required_if:reconciliationStatus,exception'],
        ];
    }
}

