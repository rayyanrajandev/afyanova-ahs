<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClinicalSpecialtyRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

