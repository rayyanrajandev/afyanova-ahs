<?php

namespace App\Modules\InpatientWard\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInpatientWardTaskRequest extends FormRequest
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
            'assignedToUserId' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'dueAt' => ['sometimes', 'nullable', 'date'],
        ];
    }
}