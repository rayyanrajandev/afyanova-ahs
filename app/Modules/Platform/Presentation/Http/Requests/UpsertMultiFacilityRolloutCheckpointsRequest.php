<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutCheckpointStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertMultiFacilityRolloutCheckpointsRequest extends FormRequest
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
            'checkpoints' => ['required', 'array', 'min:1'],
            'checkpoints.*.checkpointCode' => ['required', 'string', 'max:80'],
            'checkpoints.*.checkpointName' => ['required', 'string', 'max:180'],
            'checkpoints.*.status' => ['required', Rule::in(MultiFacilityRolloutCheckpointStatus::values())],
            'checkpoints.*.decisionNotes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
