<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Requests;

use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceAllocationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTheatreProcedureResourceAllocationStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(TheatreProcedureResourceAllocationStatus::values())],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:status,cancelled'],
            'actualStartAt' => ['nullable', 'date'],
            'actualEndAt' => ['nullable', 'date', 'required_if:status,released'],
        ];
    }
}
