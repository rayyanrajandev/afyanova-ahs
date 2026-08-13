<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

use App\Modules\Platform\Infrastructure\Models\PlatformSubscriptionPlanAuditLogModel;
use App\Support\Audit\AuditLogPresenter;

class PlatformSubscriptionPlanAuditLogResponseTransformer
{
    /**
     * @return array<string, mixed>
     */
    public static function transform(PlatformSubscriptionPlanAuditLogModel $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log->id,
            'planId' => $log->plan_id,
            'actorId' => $log->actor_id,
            'action' => $log->action,
            'changes' => $log->changes ?? [],
            'metadata' => $log->metadata ?? [],
            'createdAt' => $log->created_at?->toISOString(),
        ], [
            'actor_id' => $log->actor_id,
            'action' => $log->action,
        ], [
            'platform.subscription-plans.updated' => 'Service plan updated',
        ]);
    }
}
