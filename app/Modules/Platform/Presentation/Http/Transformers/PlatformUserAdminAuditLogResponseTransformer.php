<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class PlatformUserAdminAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'tenantId' => $log['tenant_id'] ?? null,
            'facilityId' => $log['facility_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'targetUserId' => $log['target_user_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'platform-user.created' => 'User Created',
            'platform-user.updated' => 'User Updated',
            'platform-user.status.updated' => 'User Status Updated',
            'platform-user.facilities.synced' => 'User Facilities Synced',
            'platform-user.invite-link.sent' => 'Invite Link Sent',
            'platform-user.password-reset-link.sent' => 'Password Reset Link Sent',
        ]);
    }
}
