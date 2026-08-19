<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;
use App\Modules\Revenue\Domain\Exceptions\CashierSessionAlreadyOpenException;
use App\Modules\Revenue\Domain\Services\DocumentNumberAllocatorInterface;
use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use Illuminate\Support\Facades\DB;

class OpenCashierSessionUseCase
{
    public function __construct(
        private readonly DocumentNumberAllocatorInterface $numberAllocator,
        private readonly RevenueAuditRecorderInterface $auditRecorder,
        private readonly DefaultCurrencyResolverInterface $currencyResolver,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function execute(int $cashierUserId, int $openingFloatMinor, ?int $actorUserId = null): CashierSessionModel
    {
        $existing = CashierSessionModel::query()
            ->where('cashier_user_id', $cashierUserId)
            ->where('status', CashierSessionStatus::OPEN->value)
            ->first();

        if ($existing !== null) {
            throw new CashierSessionAlreadyOpenException((string) $existing->session_number);
        }

        $tenantId = $this->scopeContext->tenantId();
        $facilityId = $this->scopeContext->facilityId();
        $currencyCode = $this->currencyResolver->resolve();
        $float = Money::of(max(0, $openingFloatMinor), $currencyCode);

        return DB::transaction(function () use (
            $cashierUserId, $float, $currencyCode, $tenantId, $facilityId, $actorUserId
        ): CashierSessionModel {
            $session = CashierSessionModel::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'session_number' => $this->numberAllocator->allocate('cashier_session', $tenantId, $facilityId),
                'cashier_user_id' => $cashierUserId,
                'currency_code' => $currencyCode,
                'opened_at' => now(),
                'opened_by_user_id' => $actorUserId ?? $cashierUserId,
                'opening_float_minor' => $float->minorUnits,
                'status' => CashierSessionStatus::OPEN->value,
            ]);

            $this->auditRecorder->record(
                entityType: 'cashier_session',
                entityId: (string) $session->id,
                action: 'opened',
                actorUserId: $actorUserId ?? $cashierUserId,
                amount: $float,
                after: [
                    'sessionNumber' => $session->session_number,
                    'cashierUserId' => $cashierUserId,
                    'openingFloat' => $float->toDecimalString(),
                ],
                cashierSessionId: (string) $session->id,
            );

            return $session;
        });
    }
}
