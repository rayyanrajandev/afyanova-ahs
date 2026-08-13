<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutPlanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMultiFacilityRolloutPlanRequest extends FormRequest
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
            'facilityId' => ['required', 'uuid', 'exists:facilities,id'],
            'rolloutCode' => ['required', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(MultiFacilityRolloutPlanStatus::values())],
            'targetGoLiveAt' => ['nullable', 'date'],
            'actualGoLiveAt' => ['nullable', 'date'],
            'ownerUserId' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
