<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Requests;

use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceAllocationStatus;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTheatreProcedureResourceAllocationRequest extends FormRequest
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
            'resourceType' => ['required', Rule::in(TheatreProcedureResourceType::values())],
            'resourceReference' => ['required', 'string', 'max:180'],
            'roleLabel' => ['nullable', 'string', 'max:120'],
            'plannedStartAt' => ['required', 'date'],
            'plannedEndAt' => ['required', 'date', 'after_or_equal:plannedStartAt'],
            'actualStartAt' => ['nullable', 'date'],
            'actualEndAt' => ['nullable', 'date', 'after_or_equal:actualStartAt'],
            'status' => ['nullable', Rule::in(TheatreProcedureResourceAllocationStatus::values())],
            'statusReason' => ['nullable', 'string', 'max:500', 'required_if:status,cancelled'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
