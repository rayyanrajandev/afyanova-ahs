<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class PlatformUserResponseTransformer
{
    public static function transform(array $user): array
    {
        return [
            'id' => $user['id'] ?? null,
            'tenantId' => $user['tenant_id'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'status' => $user['status'] ?? null,
            'statusReason' => $user['status_reason'] ?? null,
            'deactivatedAt' => $user['deactivated_at'] ?? null,
            'emailVerifiedAt' => $user['email_verified_at'] ?? null,
            'createdAt' => $user['created_at'] ?? null,
            'updatedAt' => $user['updated_at'] ?? null,
            'roleIds' => array_values($user['role_ids'] ?? []),
            'roles' => array_map(static fn (array $role): array => [
                'id' => $role['id'] ?? null,
                'code' => $role['code'] ?? null,
                'name' => $role['name'] ?? null,
            ], $user['roles'] ?? []),
            'requiresApprovalCaseForSensitiveChanges' => (bool) ($user['requires_approval_case_for_sensitive_changes'] ?? false),
            'privilegedTargetUser' => isset($user['privileged_target_user']) && is_array($user['privileged_target_user'])
                ? [
                    'isPrivileged' => (bool) ($user['privileged_target_user']['is_privileged'] ?? false),
                    'matchedPermissionNames' => array_values(array_map(
                        static fn ($value): string => (string) $value,
                        (array) ($user['privileged_target_user']['matched_permission_names'] ?? []),
                    )),
                    'roleCodes' => array_values(array_map(
                        static fn ($value): string => (string) $value,
                        (array) ($user['privileged_target_user']['role_codes'] ?? []),
                    )),
                    'systemRoleCodes' => array_values(array_map(
                        static fn ($value): string => (string) $value,
                        (array) ($user['privileged_target_user']['system_role_codes'] ?? []),
                    )),
                ]
                : null,
            'facilityAssignments' => array_map(static fn (array $assignment): array => [
                'facilityId' => $assignment['facility_id'] ?? null,
                'facilityCode' => $assignment['facility_code'] ?? null,
                'facilityName' => $assignment['facility_name'] ?? null,
                'tenantId' => $assignment['tenant_id'] ?? null,
                'tenantCode' => $assignment['tenant_code'] ?? null,
                'tenantName' => $assignment['tenant_name'] ?? null,
                'role' => $assignment['role'] ?? null,
                'isPrimary' => (bool) ($assignment['is_primary'] ?? false),
                'isActive' => (bool) ($assignment['is_active'] ?? false),
            ], $user['facility_assignments'] ?? []),
        ];
    }
}
