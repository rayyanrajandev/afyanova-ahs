<?php

namespace App\Modules\InpatientWard\Presentation\Http\Requests;

use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardDischargeChecklistStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInpatientWardDischargeChecklistStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(InpatientWardDischargeChecklistStatus::values())],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:status,blocked'],
        ];
    }
}

