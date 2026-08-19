<?php

namespace App\Modules\Revenue\Presentation\Http\Transformers;

use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;

class CashierSessionResponseTransformer
{
    /**
     * The blind count lives here, not only in the UI.
     *
     * `expectedCash` and `variance` are withheld while the drawer is still
     * open, so the figure a cashier is measured against genuinely is not
     * available to them until they have committed to a count. A close screen
     * that merely hides it is defeated by the network tab.
     *
     * @return array<string, mixed>
     */
    public static function transform(CashierSessionModel $session): array
    {
        $currency = (string) $session->currency_code;
        $counted = $session->status !== CashierSessionStatus::OPEN;

        return [
            'id' => (string) $session->id,
            'sessionNumber' => (string) $session->session_number,
            'cashierUserId' => (int) $session->cashier_user_id,
            'status' => $session->status->value,
            'currencyCode' => $currency,
            'openingFloat' => $session->openingFloat()->toDecimalString(),
            'openedAt' => $session->opened_at?->toIso8601String(),
            'countedAt' => $session->counted_at?->toIso8601String(),
            'closedAt' => $session->closed_at?->toIso8601String(),
            'declaredCash' => $session->declared_cash_minor === null
                ? null
                : Money::of((int) $session->declared_cash_minor, $currency)->toDecimalString(),
            'expectedCash' => $counted && $session->expected_cash_minor !== null
                ? Money::of((int) $session->expected_cash_minor, $currency)->toDecimalString()
                : null,
            'variance' => $counted && $session->variance_minor !== null
                ? Money::of((int) $session->variance_minor, $currency)->toDecimalString()
                : null,
            'requiresVarianceApproval' => $session->status === CashierSessionStatus::PENDING_APPROVAL,
            'approvedByUserId' => $session->approved_by_user_id,
            'approvalNote' => $session->approval_note,
        ];
    }
}
