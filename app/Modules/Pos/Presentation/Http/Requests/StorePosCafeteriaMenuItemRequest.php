<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use App\Modules\Pos\Domain\ValueObjects\PosCatalogItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosCafeteriaMenuItemRequest extends FormRequest
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
            'itemCode' => ['nullable', 'string', 'max:40'],
            'itemName' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:80'],
            'unitLabel' => ['nullable', 'string', 'max:40'],
            'unitPrice' => ['required', 'numeric', 'gt:0'],
            'taxRatePercent' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'status' => ['nullable', 'string', Rule::in(PosCatalogItemStatus::values())],
            'statusReason' => ['nullable', 'string', 'max:255', 'required_if:status,inactive'],
            'sortOrder' => ['nullable', 'integer', 'gte:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
