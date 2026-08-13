<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\FacilityResourceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFacilityResourceStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(FacilityResourceStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,inactive'],
        ];
    }
}

