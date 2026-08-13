<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class PlatformUserApprovalCaseAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'approvalCaseId' => $log['approval_case_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'platform.user-approval-case.created' => 'Approval Case Created',
            'platform.user-approval-case.status.updated' => 'Approval Case Status Updated',
            'platform.user-approval-case.decided' => 'Approval Case Decided',
            'platform.user-approval-case.comment.added' => 'Approval Case Comment Added',
        ]);
    }
}

