<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementReason;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryStockMovementRequest extends FormRequest
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
            'movementType' => ['required', Rule::in(InventoryStockMovementType::values())],
            'adjustmentDirection' => ['nullable', Rule::in(['increase', 'decrease']), 'required_if:movementType,adjust'],
            'batchId' => ['nullable', 'uuid'],
            'batchNumber' => ['nullable', 'string', 'max:120'],
            'lotNumber' => ['nullable', 'string', 'max:120'],
            'manufactureDate' => ['nullable', 'date'],
            'expiryDate' => ['nullable', 'date', 'after_or_equal:manufactureDate'],
            'binLocation' => ['nullable', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:180'],
            'sourceSupplierId' => ['nullable', 'uuid'],
            'sourceWarehouseId' => ['nullable', 'uuid'],
            'destinationWarehouseId' => ['nullable', 'uuid'],
            'destinationDepartmentId' => ['nullable', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:movementType,adjust,issue,transfer'],
            'reasonCode' => ['nullable', 'string', 'max:50', Rule::in(InventoryStockMovementReason::values())],
            'isOpeningStock' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'occurredAt' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
