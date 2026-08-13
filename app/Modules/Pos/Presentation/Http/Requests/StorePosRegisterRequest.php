<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePosRegisterRequest extends FormRequest
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
            'registerCode' => ['required', 'string', 'max:40'],
            'registerName' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'defaultCurrencyCode' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
