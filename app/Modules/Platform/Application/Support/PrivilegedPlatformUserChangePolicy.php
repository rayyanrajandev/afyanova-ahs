<?php

namespace App\Modules\Platform\Application\Support;

use App\Modules\Platform\Application\Exceptions\PrivilegedPlatformUserApprovalCaseException;

class PrivilegedPlatformUserChangePolicy
{
    private const DEFAULT_APPROVAL_CASE_REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9\\-_\\/.:]{5,119}$/';

    public function normalizeApprovalCaseReference(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $privilegedContext
     */
    public function assertApprovalCaseReferenceForTarget(
        ?array $privilegedContext,
        ?string $approvalCaseReference
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        if ($approvalCaseReference !== null) {
            $this->assertApprovalCaseReferencePattern($approvalCaseReference);
        }

        if (! $this->isPrivilegedTarget($privilegedContext)) {
            return;
        }

        if ($approvalCaseReference === null) {
            throw new PrivilegedPlatformUserApprovalCaseException(
                'Approval case reference is required for privileged user changes.',
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $privilegedContext
     * @return array<string, mixed>
     */
    public function buildAuditMetadata(?array $privilegedContext, ?string $approvalCaseReference): array
    {
        $metadata = [];
        if ($approvalCaseReference !== null) {
            $metadata['approval_case_reference'] = $approvalCaseReference;
        }

        if (! $this->isPrivilegedTarget($privilegedContext)) {
            return $metadata;
        }

        $metadata['privileged_target_user'] = [
            'matched_permission_names' => array_values(array_map(
                static fn ($value): string => (string) $value,
                (array) ($privilegedContext['matched_permission_names'] ?? []),
            )),
            'system_role_codes' => array_values(array_map(
                static fn ($value): string => (string) $value,
                (array) ($privilegedContext['system_role_codes'] ?? []),
            )),
        ];

        return $metadata;
    }

    private function assertApprovalCaseReferencePattern(string $approvalCaseReference): void
    {
        $pattern = (string) config(
            'platform_user_admin.privileged_change_controls.approval_case_reference.pattern',
            self::DEFAULT_APPROVAL_CASE_REFERENCE_PATTERN,
        );

        $result = @preg_match($pattern, $approvalCaseReference);
        if ($result === 1) {
            return;
        }

        throw new PrivilegedPlatformUserApprovalCaseException(
            'Approval case reference format is invalid.',
        );
    }

    private function isEnabled(): bool
    {
        return (bool) config(
            'platform_user_admin.privileged_change_controls.enabled',
            false,
        );
    }

    /**
     * @param  array<string, mixed>|null  $privilegedContext
     */
    private function isPrivilegedTarget(?array $privilegedContext): bool
    {
        return (bool) ($privilegedContext['is_privileged'] ?? false);
    }
}
