<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryDepartmentRequisitionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryDepartmentRequisitionRequest extends FormRequest
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
            'requestingDepartment' => ['required', 'string', 'max:120'],
            'requestingDepartmentId' => ['nullable', 'uuid'],
            'issuingStore' => ['nullable', 'string', 'max:120'],
            'issuingWarehouseId' => ['nullable', 'uuid'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'neededBy' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.itemId' => ['required', 'uuid'],
            'lines.*.batchId' => ['nullable', 'uuid'],
            'lines.*.requestedQuantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', 'string', 'max:40'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
