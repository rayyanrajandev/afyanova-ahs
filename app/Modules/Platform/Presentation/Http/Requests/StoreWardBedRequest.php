<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWardBedRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:180'],
            'departmentId' => ['nullable', 'uuid', 'exists:departments,id'],
            'wardName' => ['required', 'string', 'max:120'],
            'bedNumber' => ['required', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'chargeableItemId' => ['nullable', 'uuid', 'exists:chargeable_items,id'],
        ];
    }
}

