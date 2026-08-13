<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Requests;

use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTheatreProcedureStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('theatre.procedures.update-status') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(TheatreProcedureStatus::values())],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:status,cancelled'],
            'startedAt' => ['nullable', 'date'],
            'completedAt' => ['nullable', 'date', 'required_if:status,completed'],
        ];
    }
}
