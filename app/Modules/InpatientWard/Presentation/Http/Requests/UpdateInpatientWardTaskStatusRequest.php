<?php

namespace App\Modules\InpatientWard\Presentation\Http\Requests;

use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardTaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInpatientWardTaskStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(InpatientWardTaskStatus::values())],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:status,escalated,cancelled'],
        ];
    }
}
