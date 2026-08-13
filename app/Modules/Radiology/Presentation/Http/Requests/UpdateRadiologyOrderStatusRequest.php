<?php

namespace App\Modules\Radiology\Presentation\Http\Requests;

use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRadiologyOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('imaging.perform') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(RadiologyOrderStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled'],
            'reportSummary' => ['nullable', 'string', 'max:5000', 'required_if:status,completed'],
        ];
    }
}
