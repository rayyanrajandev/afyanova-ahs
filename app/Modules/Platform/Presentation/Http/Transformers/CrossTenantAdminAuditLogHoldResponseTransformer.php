<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

class CrossTenantAdminAuditLogHoldResponseTransformer
{
    public static function transform(array $hold): array
    {
        return [
            'id' => $hold['id'] ?? null,
            'holdCode' => $hold['hold_code'] ?? null,
            'reason' => $hold['reason'] ?? null,
            'approvalCaseReference' => $hold['approval_case_reference'] ?? null,
            'targetTenantCode' => $hold['target_tenant_code'] ?? null,
            'action' => $hold['action'] ?? null,
            'startsAt' => $hold['starts_at'] ?? null,
            'endsAt' => $hold['ends_at'] ?? null,
            'isActive' => $hold['is_active'] ?? null,
            'createdByUserId' => $hold['created_by_user_id'] ?? null,
            'approvedByUserId' => $hold['approved_by_user_id'] ?? null,
            'reviewDueAt' => $hold['review_due_at'] ?? null,
            'releasedAt' => $hold['released_at'] ?? null,
            'releasedByUserId' => $hold['released_by_user_id'] ?? null,
            'releaseReason' => $hold['release_reason'] ?? null,
            'releaseCaseReference' => $hold['release_case_reference'] ?? null,
            'releaseApprovedByUserId' => $hold['release_approved_by_user_id'] ?? null,
            'createdAt' => $hold['created_at'] ?? null,
            'updatedAt' => $hold['updated_at'] ?? null,
        ];
    }
}
