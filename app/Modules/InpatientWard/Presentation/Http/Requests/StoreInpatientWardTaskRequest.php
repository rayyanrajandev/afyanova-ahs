<?php

namespace App\Modules\InpatientWard\Presentation\Http\Requests;

use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardTaskPriority;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardTaskType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInpatientWardTaskRequest extends FormRequest
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
            'admissionId' => ['required', 'uuid'],
            'taskType' => ['required', Rule::in(InpatientWardTaskType::values())],
            'title' => ['nullable', 'string', 'max:180'],
            'priority' => ['required', Rule::in(InpatientWardTaskPriority::values())],
            'assignedToUserId' => ['nullable', 'integer', 'exists:users,id'],
            'dueAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
