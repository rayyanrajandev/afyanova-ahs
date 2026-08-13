<?php

namespace App\Modules\Staff\Presentation\Http\Requests;

use App\Modules\Staff\Domain\ValueObjects\StaffPrivilegeGrantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateStaffPrivilegeGrantStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $permission = $this->requiredPermissionForStatus((string) $this->input('status'));
        if ($permission !== null) {
            return Gate::forUser($user)->allows($permission);
        }

        return $this->canManageAnyWorkflowStage($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(StaffPrivilegeGrantStatus::values())],
            'reason' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(
                    fn (): bool => in_array($this->normalizedStatus(), [
                        StaffPrivilegeGrantStatus::UNDER_REVIEW->value,
                        StaffPrivilegeGrantStatus::APPROVED->value,
                    ], true)
                        || StaffPrivilegeGrantStatus::requiresReason((string) $this->input('status')),
                ),
            ],
        ];
    }

    private function normalizedStatus(): string
    {
        return strtolower(trim((string) $this->input('status')));
    }

    private function requiredPermissionForStatus(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            StaffPrivilegeGrantStatus::REQUESTED->value,
            StaffPrivilegeGrantStatus::UNDER_REVIEW->value => 'staff.privileges.review',
            StaffPrivilegeGrantStatus::APPROVED->value => 'staff.privileges.approve',
            StaffPrivilegeGrantStatus::ACTIVE->value,
            StaffPrivilegeGrantStatus::SUSPENDED->value,
            StaffPrivilegeGrantStatus::RETIRED->value => 'staff.privileges.update-status',
            default => null,
        };
    }

    private function canManageAnyWorkflowStage(object $user): bool
    {
        $gate = Gate::forUser($user);

        foreach ([
            'staff.privileges.review',
            'staff.privileges.approve',
            'staff.privileges.update-status',
        ] as $permission) {
            if ($gate->allows($permission)) {
                return true;
            }
        }

        return false;
    }
}
