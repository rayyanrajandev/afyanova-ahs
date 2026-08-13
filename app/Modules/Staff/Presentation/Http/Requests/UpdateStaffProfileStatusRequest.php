<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use App\Modules\Staff\Domain\ValueObjects\StaffProfileStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffProfileStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('staff.update-status') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(StaffProfileStatus::values())],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,inactive,suspended'],
        ];
    }
}
