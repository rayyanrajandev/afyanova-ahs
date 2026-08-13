<?php

namespace App\Modules\Pharmacy\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPharmacyOrderDispenseRequest extends FormRequest
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
            'verificationNote' => ['nullable', 'string', 'max:5000'],
        ];
    }
}


