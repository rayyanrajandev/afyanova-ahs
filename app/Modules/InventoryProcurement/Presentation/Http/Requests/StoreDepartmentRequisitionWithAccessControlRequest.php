<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 1: Department-Level RBAC Implementation
 * Request validation for creating department requisitions with access control
 */
class StoreDepartmentRequisitionWithAccessControlRequest extends FormRequest
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
            'requestingDepartmentId' => ['required', 'uuid'],
            'issuingWarehouseId' => ['required', 'uuid'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'neededBy' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.itemId' => ['required', 'uuid'],
            'lines.*.requestedQuantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', 'string', 'max:40'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'requestingDepartmentId.required' => 'Requesting department is required',
            'requestingDepartmentId.uuid' => 'Requesting department must be a valid ID',
            'issuingWarehouseId.required' => 'Issuing warehouse is required',
            'issuingWarehouseId.uuid' => 'Issuing warehouse must be a valid ID',
            'lines.required' => 'At least one requisition line is required',
            'lines.*.itemId.required' => 'Item ID is required for each line',
            'lines.*.requestedQuantity.required' => 'Requested quantity is required for each line',
            'lines.*.requestedQuantity.gt' => 'Requested quantity must be greater than 0',
        ];
    }
}
