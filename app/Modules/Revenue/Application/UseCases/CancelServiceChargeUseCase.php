<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Withdraw a charge that should never have been raised.
 *
 * Only while it is still unpaid. Once money has been taken the correction is a
 * reversal or a refund — cancelling a settled charge would leave the payment
 * allocated to something that no longer exists.
 */
class CancelServiceChargeUseCase
{
    public function __construct(
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    public function execute(string $serviceChargeId, string $reason, int $actorUserId): ServiceChargeModel
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Cancelling a charge must say why.');
        }

        return DB::transaction(function () use ($serviceChargeId, $reason, $actorUserId): ServiceChargeModel {
            $charge = ServiceChargeModel::query()->lockForUpdate()->findOrFail($serviceChargeId);

            if (! $charge->status->isOutstanding()) {
                throw new RuntimeException(sprintf(
                    'Charge %s is %s — only an unpaid charge can be cancelled.',
                    $charge->charge_number,
                    $charge->status->value,
                ));
            }

            $previous = $charge->status->value;

            $charge->status = ServiceChargeStatus::CANCELLED;
            $charge->cancelled_at = now();
            $charge->cancelled_by_user_id = $actorUserId;
            $charge->cancellation_reason = $reason;
            $charge->save();

            $this->auditRecorder->record(
                entityType: 'service_charge',
                entityId: (string) $charge->id,
                action: 'cancelled',
                actorUserId: $actorUserId,
                amount: $charge->netAmount(),
                before: ['status' => $previous],
                after: ['status' => ServiceChargeStatus::CANCELLED->value],
                reason: $reason,
            );

            return $charge;
        });
    }
}
