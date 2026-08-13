<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClosePosRegisterSessionRequest extends FormRequest
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
            'closingCashAmount' => ['required', 'numeric', 'gte:0'],
            'closingNote' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
