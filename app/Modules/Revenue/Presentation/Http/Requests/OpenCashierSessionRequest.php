<?php

namespace App\Modules\Revenue\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashierSessionRequest extends FormRequest
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
            'openingFloatMinor' => ['required', 'integer', 'min:0'],
        ];
    }
}
