<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryWarehouseRequest extends FormRequest
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
            'warehouseCode' => ['required', 'string', 'max:40'],
            'warehouseName' => ['required', 'string', 'max:180'],
            'warehouseType' => ['nullable', 'string', 'max:60'],
            'location' => ['nullable', 'string', 'max:255'],
            'contactPerson' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

