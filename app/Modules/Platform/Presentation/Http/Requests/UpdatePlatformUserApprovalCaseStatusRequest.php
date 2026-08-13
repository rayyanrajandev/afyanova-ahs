<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\PlatformUserApprovalCaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformUserApprovalCaseStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(PlatformUserApprovalCaseStatus::statusTransitionValues())],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:status,cancelled'],
            'decision' => ['prohibited'],
        ];
    }
}
