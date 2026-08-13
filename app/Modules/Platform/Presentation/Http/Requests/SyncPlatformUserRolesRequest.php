<?php

namespace App\Modules\Platform\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncPlatformUserRolesRequest extends FormRequest
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
            'roleIds' => ['required', 'array'],
            'roleIds.*' => ['string', 'uuid'],
            'approvalCaseReference' => [
                'nullable',
                'string',
                'max:120',
                'regex:'.(string) config(
                    'platform_user_admin.privileged_change_controls.approval_case_reference.pattern',
                    '/^[A-Za-z0-9][A-Za-z0-9\\-_\\/.:]{5,119}$/',
                ),
            ],
        ];
    }
}
