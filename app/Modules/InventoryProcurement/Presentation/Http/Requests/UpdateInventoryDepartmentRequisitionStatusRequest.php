<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryDepartmentRequisitionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryDepartmentRequisitionStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(InventoryDepartmentRequisitionStatus::values())],
            'rejectionReason' => ['nullable', 'string', 'max:1000', 'required_if:status,rejected'],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.approvedQuantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.issuedQuantity' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
