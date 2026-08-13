<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Requests;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryItemStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(InventoryItemStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,inactive'],
        ];
    }
}

