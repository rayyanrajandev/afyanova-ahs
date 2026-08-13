<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicalCatalogItemStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(ClinicalCatalogItemStatus::values())],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:status,inactive,retired'],
        ];
    }
}
