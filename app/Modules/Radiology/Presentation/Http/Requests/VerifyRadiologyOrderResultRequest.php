<?php

namespace App\Modules\Radiology\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyRadiologyOrderResultRequest extends FormRequest
{
    /**
     * Mirrors the route guard. Only `imaging.result.verify` is accepted — the
     * laboratory twin also honours two legacy aliases, but radiology has no
     * such history to carry, so the single canonical ability is used.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('imaging.result.verify') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'verificationNote' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
