<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A second person signs off a drawer that did not balance.
 *
 * The approver may not be the cashier whose drawer it is, nor whoever counted
 * it. Enforced here as well as in RBAC: a supervisor who also works a till
 * still must not clear their own shortage.
 */
class ApproveCashierSessionVarianceUseCase
{
    public function __construct(
        private readonly RevenueAuditRecorderInterface $auditRecorder,
    ) {}

    public function execute(string $cashierSessionId, int $approverUserId, string $note): CashierSessionModel
    {
        $note = trim($note);

        if ($note === '') {
            throw new RuntimeException('Approving a variance requires a note explaining it.');
        }

        return DB::transaction(function () use ($cashierSessionId, $approverUserId, $note): CashierSessionModel {
            $session = CashierSessionModel::query()->lockForUpdate()->findOrFail($cashierSessionId);

            if ($session->status !== CashierSessionStatus::PENDING_APPROVAL) {
                throw new RuntimeException(sprintf(
                    'Drawer %s is %s — there is no variance waiting for approval.',
                    $session->session_number,
                    $session->status->value,
                ));
            }

            if ((int) $session->cashier_user_id === $approverUserId) {
                throw new RuntimeException('A cashier cannot approve the variance on their own drawer.');
            }

            $session->status = CashierSessionStatus::CLOSED;
            $session->approved_by_user_id = $approverUserId;
            $session->approved_at = now();
            $session->approval_note = $note;
            $session->closed_at = now();
            $session->closed_by_user_id = $approverUserId;
            $session->save();

            $this->auditRecorder->record(
                entityType: 'cashier_session',
                entityId: (string) $session->id,
                action: 'variance_approved',
                actorUserId: $approverUserId,
                amount: Money::of((int) $session->variance_minor, (string) $session->currency_code),
                after: ['status' => CashierSessionStatus::CLOSED->value, 'note' => $note],
                reason: $note,
                cashierSessionId: (string) $session->id,
            );

            return $session;
        });
    }
}
