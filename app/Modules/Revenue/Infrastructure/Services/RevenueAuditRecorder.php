<?php

namespace App\Modules\Revenue\Infrastructure\Services;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Infrastructure\Models\RevenueAuditEventModel;
use Illuminate\Http\Request;
use Throwable;

class RevenueAuditRecorder implements RevenueAuditRecorderInterface
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function record(
        string $entityType,
        string $entityId,
        string $action,
        ?int $actorUserId = null,
        ?Money $amount = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $cashierSessionId = null,
    ): void {
        RevenueAuditEventModel::query()->create([
            'tenant_id' => $this->scopeContext->tenantId(),
            'facility_id' => $this->scopeContext->facilityId(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'actor_role_code' => $this->resolveActorRoleCode(),
            'cashier_session_id' => $cashierSessionId,
            'ip_address' => $this->resolveIpAddress(),
            'amount_minor' => $amount?->minorUnits,
            'currency_code' => $amount?->currencyCode,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Best-effort: the log is written from queued jobs and console commands as
     * well as requests, and a missing role must never be the reason a payment
     * fails to record.
     */
    private function resolveActorRoleCode(): ?string
    {
        try {
            $user = request()->user();
            if ($user === null || ! method_exists($user, 'roleCodes')) {
                return null;
            }

            $codes = $user->roleCodes();

            return $codes === [] ? null : (string) $codes[0];
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveIpAddress(): ?string
    {
        try {
            $request = app(Request::class);

            return $request instanceof Request ? $request->ip() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
