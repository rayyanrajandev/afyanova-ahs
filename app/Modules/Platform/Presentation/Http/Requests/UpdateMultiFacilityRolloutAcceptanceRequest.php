<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutAcceptanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMultiFacilityRolloutAcceptanceRequest extends FormRequest
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
            'trainingCompletedAt' => ['nullable', 'date'],
            'acceptanceStatus' => ['required', Rule::in(MultiFacilityRolloutAcceptanceStatus::values())],
            'acceptanceCaseReference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
