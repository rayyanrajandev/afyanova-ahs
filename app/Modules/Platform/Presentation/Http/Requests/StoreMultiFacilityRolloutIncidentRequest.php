<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutIncidentSeverity;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutIncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMultiFacilityRolloutIncidentRequest extends FormRequest
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
            'incidentCode' => ['required', 'string', 'max:80'],
            'severity' => ['required', Rule::in(MultiFacilityRolloutIncidentSeverity::values())],
            'status' => ['nullable', Rule::in(MultiFacilityRolloutIncidentStatus::values())],
            'summary' => ['required', 'string', 'max:200'],
            'details' => ['nullable', 'string', 'max:4000'],
            'escalatedTo' => ['nullable', 'string', 'max:200'],
            'openedAt' => ['nullable', 'date'],
        ];
    }
}
