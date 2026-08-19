<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ChargeWaiverModel;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authorize a service without payment.
 *
 * A hospital that cannot see an unpaid emergency patient is not a working
 * hospital, so the prepaid gate ships with a way through it. What matters is
 * that the way through is named, reasoned and attributable: the basis is
 * recorded, a reason is mandatory, and the approver is stored separately from
 * whoever asked.
 */
class WaiveServiceChargeUseCase
{
    public function __construct(
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    public function execute(
        string $serviceChargeId,
        AuthorizationBasis $basis,
        string $reason,
        int $approvedByUserId,
        ?int $requestedByUserId = null,
    ): ServiceChargeModel {
        if (! $basis->requiresReason()) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a basis a person can grant here — it is set by the ledger itself.',
                $basis->value,
            ));
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A waiver or emergency override must say why.');
        }

        // The person asking cannot be the person approving. Enforced here as
        // well as in RBAC, because a user who somehow holds both permissions
        // still must not self-approve.
        if ($requestedByUserId !== null && $requestedByUserId === $approvedByUserId) {
            throw new RuntimeException(
                'A waiver cannot be approved by the person who requested it.',
            );
        }

        return DB::transaction(function () use (
            $serviceChargeId, $basis, $reason, $approvedByUserId, $requestedByUserId
        ): ServiceChargeModel {
            $charge = ServiceChargeModel::query()->lockForUpdate()->findOrFail($serviceChargeId);

            if (! $charge->status->isOutstanding()) {
                throw new RuntimeException(sprintf(
                    'Charge %s is %s — only an outstanding charge can be waived.',
                    $charge->charge_number,
                    $charge->status->value,
                ));
            }

            $waivedAmount = $charge->outstandingAmount();

            ChargeWaiverModel::query()->create([
                'tenant_id' => $charge->tenant_id,
                'facility_id' => $charge->facility_id,
                'service_charge_id' => $charge->id,
                'basis' => $basis->value,
                'currency_code' => $charge->currency_code,
                'amount_minor' => $waivedAmount->minorUnits,
                'reason' => $reason,
                'requested_by_user_id' => $requestedByUserId,
                'approved_by_user_id' => $approvedByUserId,
                'approved_at' => now(),
            ]);

            $previousStatus = $charge->status->value;

            $charge->status = ServiceChargeStatus::AUTHORIZED;
            $charge->authorization_basis = $basis;
            $charge->authorized_at = now();
            $charge->authorized_by_user_id = $approvedByUserId;
            $charge->authorization_reference = $basis->value;
            $charge->save();

            $this->auditRecorder->record(
                entityType: 'service_charge',
                entityId: (string) $charge->id,
                action: $basis === AuthorizationBasis::EMERGENCY ? 'emergency_override' : 'waived',
                actorUserId: $approvedByUserId,
                amount: $waivedAmount,
                before: ['status' => $previousStatus],
                after: [
                    'status' => ServiceChargeStatus::AUTHORIZED->value,
                    'basis' => $basis->value,
                    'requestedByUserId' => $requestedByUserId,
                ],
                reason: $reason,
            );

            return $charge;
        });
    }
}
