<?php

namespace App\Modules\Staff\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class StaffPrivilegeGrantAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'staffPrivilegeGrantId' => $log['staff_privilege_grant_id'] ?? null,
            'staffProfileId' => $log['staff_profile_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'staff-privilege-grant.created' => 'Privilege Request Submitted',
            'staff-privilege-grant.updated' => 'Privilege Updated',
            'staff-privilege-grant.status.updated' => 'Privilege Status Updated',
        ]);
    }
}
