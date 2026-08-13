<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileInventoryStockRequest extends FormRequest
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
            'batchId' => ['nullable', 'uuid'],
            'countedStock' => ['nullable', 'numeric', 'min:0', 'required_without:countedBatchQuantity'],
            'countedBatchQuantity' => ['nullable', 'numeric', 'min:0', 'required_without:countedStock'],
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'occurredAt' => ['nullable', 'date'],
            'sessionReference' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
