<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryBatchRequest extends FormRequest
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
            'itemId' => ['required', 'uuid'],
            'batchNumber' => ['nullable', 'string', 'max:100'],
            'lotNumber' => ['nullable', 'string', 'max:100'],
            'manufactureDate' => ['nullable', 'date'],
            'expiryDate' => ['nullable', 'date', 'after_or_equal:manufactureDate'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'warehouseId' => ['nullable', 'uuid'],
            'binLocation' => ['nullable', 'string', 'max:60'],
            'supplierId' => ['nullable', 'uuid'],
            'manufacturer' => ['nullable', 'string', 'max:180'],
            'unitCost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
