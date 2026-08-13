<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceInventoryProcurementOrderRequest extends FormRequest
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
            'purchaseOrderNumber' => ['required', 'string', 'max:100'],
            'orderedQuantity' => ['required', 'numeric', 'gt:0'],
            'unitCostEstimate' => ['nullable', 'numeric', 'min:0'],
            'neededBy' => ['nullable', 'date'],
            'supplierId' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

