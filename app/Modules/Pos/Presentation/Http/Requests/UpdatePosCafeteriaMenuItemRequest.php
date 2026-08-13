<?php

namespace App\Modules\Pos\Presentation\Http\Requests;

use App\Modules\Pos\Domain\ValueObjects\PosCatalogItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePosCafeteriaMenuItemRequest extends FormRequest
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
            'itemCode' => ['sometimes', 'nullable', 'string', 'max:40'],
            'itemName' => ['sometimes', 'required', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'unitLabel' => ['sometimes', 'nullable', 'string', 'max:40'],
            'unitPrice' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'taxRatePercent' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'lte:100'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(PosCatalogItemStatus::values())],
            'statusReason' => ['sometimes', 'nullable', 'string', 'max:255', 'required_if:status,inactive'],
            'sortOrder' => ['sometimes', 'nullable', 'integer', 'gte:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
