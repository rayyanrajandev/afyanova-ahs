<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenPosRegisterSessionRequest extends FormRequest
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
            'openingCashAmount' => ['required', 'numeric', 'gte:0'],
            'openingNote' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
