<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveInventoryProcurementRequestRequest extends FormRequest
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
            'receivedQuantity' => ['required', 'numeric', 'gt:0'],
            'receivedUnitCost' => ['nullable', 'numeric', 'min:0'],
            'receivedUnit' => ['nullable', 'string', 'max:100'],
            'warehouseId' => ['nullable', 'uuid'],
            'batchNumber' => ['nullable', 'string', 'max:100'],
            'lotNumber' => ['nullable', 'string', 'max:100'],
            'manufactureDate' => ['nullable', 'date'],
            'expiryDate' => ['nullable', 'date', 'after_or_equal:manufactureDate'],
            'binLocation' => ['nullable', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'occurredAt' => ['nullable', 'date'],
        ];
    }
}
