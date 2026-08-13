<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventorySupplierRequest extends FormRequest
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
            'supplierCode' => ['required', 'string', 'max:40'],
            'supplierName' => ['required', 'string', 'max:180'],
            'tinNumber' => ['nullable', 'string', 'max:30'],
            'bankAccountNumber' => ['nullable', 'string', 'max:80'],
            'lipaNumber' => ['nullable', 'string', 'max:80'],
            'contactPerson' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'addressLine' => ['nullable', 'string', 'max:2000'],
            'countryCode' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
