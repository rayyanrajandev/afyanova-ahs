<?php

namespace App\Modules\Laboratory\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyLaboratoryOrderResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('lab.result.verify') ?? false;
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
