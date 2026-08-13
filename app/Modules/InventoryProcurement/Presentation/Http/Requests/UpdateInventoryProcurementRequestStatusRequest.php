<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryProcurementRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryProcurementRequestStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in($this->manualStatusValues())],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:status,rejected,cancelled'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function manualStatusValues(): array
    {
        return [
            InventoryProcurementRequestStatus::DRAFT->value,
            InventoryProcurementRequestStatus::PENDING_APPROVAL->value,
            InventoryProcurementRequestStatus::APPROVED->value,
            InventoryProcurementRequestStatus::REJECTED->value,
            InventoryProcurementRequestStatus::CANCELLED->value,
        ];
    }
}
