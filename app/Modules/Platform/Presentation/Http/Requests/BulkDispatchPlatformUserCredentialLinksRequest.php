<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDispatchPlatformUserCredentialLinksRequest extends FormRequest
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
            'userIds' => ['required', 'array', 'min:1', 'max:100'],
            'userIds.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }
}
