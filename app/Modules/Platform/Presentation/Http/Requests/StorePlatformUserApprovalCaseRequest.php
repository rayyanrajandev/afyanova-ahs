<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use App\Modules\Platform\Domain\ValueObjects\PlatformUserApprovalCaseActionType;
use App\Modules\Platform\Domain\ValueObjects\PlatformUserApprovalCaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlatformUserApprovalCaseRequest extends FormRequest
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
            'facilityId' => ['nullable', 'uuid', 'exists:facilities,id'],
            'targetUserId' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'requesterUserId' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'reviewerUserId' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'caseReference' => [
                'required',
                'string',
                'max:120',
                'regex:'.(string) config(
                    'platform_user_admin.privileged_change_controls.approval_case_reference.pattern',
                    '/^[A-Za-z0-9][A-Za-z0-9\\-_\\/.:]{5,119}$/',
                ),
            ],
            'actionType' => ['required', 'string', Rule::in(PlatformUserApprovalCaseActionType::values())],
            'actionPayload' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in([
                PlatformUserApprovalCaseStatus::DRAFT->value,
                PlatformUserApprovalCaseStatus::SUBMITTED->value,
            ])],
        ];
    }
}

