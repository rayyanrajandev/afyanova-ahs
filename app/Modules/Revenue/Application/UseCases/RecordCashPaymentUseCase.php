<?php

namespace App\Modules\Revenue\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Revenue\Domain\Exceptions\CashierSessionRequiredException;
use App\Modules\Revenue\Domain\Exceptions\InsufficientTenderException;
use App\Modules\Revenue\Domain\Services\ChargeAuthorizationPolicyResolverInterface;
use App\Modules\Revenue\Domain\Services\DocumentNumberAllocatorInterface;
use App\Modules\Revenue\Domain\Services\RevenueAuditRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\AuthorizationBasis;
use App\Modules\Revenue\Domain\ValueObjects\CashierSessionStatus;
use App\Modules\Revenue\Domain\ValueObjects\Money;
use App\Modules\Revenue\Domain\ValueObjects\PaymentMethod;
use App\Modules\Revenue\Domain\ValueObjects\PaymentStatus;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\CashierSessionModel;
use App\Modules\Revenue\Infrastructure\Models\PaymentAllocationModel;
use App\Modules\Revenue\Infrastructure\Models\PaymentModel;
use App\Modules\Revenue\Infrastructure\Models\ReceiptModel;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The counter transaction: take cash, settle charges, issue a receipt.
 *
 * All of it in one database transaction, because the four things it produces —
 * a payment, its allocations, the charges it clears, and the receipt — are
 * only meaningful together. A receipt without allocations is a claim the
 * facility cannot support; allocations without a receipt is money the patient
 * cannot prove they paid.
 *
 * Idempotent by key. A double-tapped Take payment button, or a retried request
 * over a bad connection, returns the original receipt instead of taking the
 * money twice — the single most damaging bug a cashier screen can have.
 */
class RecordCashPaymentUseCase
{
    public function __construct(
        private readonly DocumentNumberAllocatorInterface $numberAllocator,
        private readonly RevenueAuditRecorderInterface $auditRecorder,
        private readonly ChargeAuthorizationPolicyResolverInterface $policyResolver,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    /**
     * @param  list<string>  $serviceChargeIds
     */
    public function execute(
        string $patientId,
        array $serviceChargeIds,
        int $tenderedAmountMinor,
        string $idempotencyKey,
        int $cashierUserId,
        ?string $cashierSessionId = null,
    ): PaymentModel {
        if ($serviceChargeIds === []) {
            throw new InvalidArgumentException('A payment must settle at least one charge.');
        }

        // Replay before doing anything else: the caller may be retrying a
        // request that already succeeded.
        $replay = PaymentModel::query()
            ->with(['allocations', 'receipt'])
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($replay !== null) {
            return $replay;
        }

        $session = $this->resolveOpenSession($cashierUserId, $cashierSessionId);

        return DB::transaction(function () use (
            $patientId, $serviceChargeIds, $tenderedAmountMinor, $idempotencyKey,
            $cashierUserId, $session
        ): PaymentModel {
            // Lock the charges for the duration: two cashiers must not settle
            // the same charge, and the losing one has to see the first one's
            // allocation rather than a stale zero.
            $charges = ServiceChargeModel::query()
                ->whereIn('id', $serviceChargeIds)
                ->lockForUpdate()
                ->get();

            if ($charges->count() !== count(array_unique($serviceChargeIds))) {
                throw new InvalidArgumentException('One or more charges could not be found.');
            }

            $currencyCode = (string) $session->currency_code;
            $due = Money::zero($currencyCode);

            foreach ($charges as $charge) {
                if ((string) $charge->patient_id !== $patientId) {
                    throw new InvalidArgumentException(
                        'Every charge in one payment must belong to the same patient.',
                    );
                }

                if ((string) $charge->currency_code !== $currencyCode) {
                    throw new InvalidArgumentException(
                        'Charges in different currencies cannot be settled by one payment.',
                    );
                }

                if (! $charge->status->isOutstanding()) {
                    throw new RuntimeException(sprintf(
                        'Charge %s is %s and cannot be paid again.',
                        $charge->charge_number,
                        $charge->status->value,
                    ));
                }

                $due = $due->plus($charge->outstandingAmount());
            }

            $tendered = Money::of($tenderedAmountMinor, $currencyCode);

            if ($tendered->isLessThan($due)) {
                throw new InsufficientTenderException($due, $tendered);
            }

            // Cash: the patient may hand over more than is owed. The excess is
            // change, never an over-allocation — that distinction is what keeps
            // the drawer reconcilable.
            $change = $tendered->minus($due);

            $payment = PaymentModel::query()->create([
                'tenant_id' => $session->tenant_id,
                'facility_id' => $session->facility_id,
                'payment_number' => $this->numberAllocator->allocate(
                    'payment', $session->tenant_id, $session->facility_id,
                ),
                'patient_id' => $patientId,
                'cashier_session_id' => $session->id,
                'method' => PaymentMethod::CASH->value,
                'currency_code' => $currencyCode,
                'amount_minor' => $due->minorUnits,
                'tendered_amount_minor' => $tendered->minorUnits,
                'change_amount_minor' => $change->minorUnits,
                'allocated_amount_minor' => $due->minorUnits,
                'status' => PaymentStatus::RECORDED->value,
                // Server-set. No endpoint accepts a client timestamp, so a
                // payment cannot be back-dated into a closed day.
                'received_at' => now(),
                'received_by_user_id' => $cashierUserId,
                'idempotency_key' => $idempotencyKey,
            ]);

            $lines = [];

            foreach ($charges as $charge) {
                $outstanding = $charge->outstandingAmount();

                if (! $outstanding->isPositive()) {
                    continue;
                }

                PaymentAllocationModel::query()->create([
                    'payment_id' => $payment->id,
                    'service_charge_id' => $charge->id,
                    'currency_code' => $currencyCode,
                    'amount_minor' => $outstanding->minorUnits,
                ]);

                $charge->allocated_amount_minor = $charge->allocated_amount_minor + $outstanding->minorUnits;

                $policy = $this->policyResolver->for($charge->payer_class);

                if ($policy->isSatisfiedBy($charge)) {
                    $charge->status = ServiceChargeStatus::AUTHORIZED;
                    $charge->authorization_basis = AuthorizationBasis::PAYMENT;
                    $charge->authorized_at = now();
                    $charge->authorized_by_user_id = $cashierUserId;
                    $charge->authorization_reference = (string) $payment->payment_number;
                }

                $charge->save();

                $lines[] = [
                    'chargeId' => (string) $charge->id,
                    'chargeNumber' => (string) $charge->charge_number,
                    'description' => (string) $charge->description,
                    'quantity' => (float) $charge->quantity,
                    'unitPrice' => $charge->netAmount()->toDecimalString(),
                    'amount' => $outstanding->toDecimalString(),
                ];
            }

            $receipt = ReceiptModel::query()->create([
                'tenant_id' => $session->tenant_id,
                'facility_id' => $session->facility_id,
                'receipt_number' => $this->numberAllocator->allocate(
                    'receipt', $session->tenant_id, $session->facility_id,
                ),
                'payment_id' => $payment->id,
                'patient_id' => $patientId,
                'currency_code' => $currencyCode,
                'total_minor' => $due->minorUnits,
                'snapshot' => [
                    'lines' => $lines,
                    'total' => $due->toDecimalString(),
                    'tendered' => $tendered->toDecimalString(),
                    'change' => $change->toDecimalString(),
                    'currencyCode' => $currencyCode,
                    'paymentNumber' => (string) $payment->payment_number,
                    'issuedAt' => now()->toIso8601String(),
                ],
                'issued_at' => now(),
                'issued_by_user_id' => $cashierUserId,
                // No VFD credentials are provisioned, and payment must never
                // wait on fiscalisation. A later phase flips this to 'pending'
                // and backfills out of band.
                'fiscal_status' => 'not_required',
            ]);

            $this->auditRecorder->record(
                entityType: 'payment',
                entityId: (string) $payment->id,
                action: 'recorded',
                actorUserId: $cashierUserId,
                amount: $due,
                after: [
                    'paymentNumber' => (string) $payment->payment_number,
                    'receiptNumber' => (string) $receipt->receipt_number,
                    'tendered' => $tendered->toDecimalString(),
                    'change' => $change->toDecimalString(),
                    'chargeIds' => $charges->pluck('id')->all(),
                ],
                cashierSessionId: (string) $session->id,
            );

            return $payment->fresh(['allocations', 'receipt']);
        });
    }

    private function resolveOpenSession(int $cashierUserId, ?string $cashierSessionId): CashierSessionModel
    {
        $query = CashierSessionModel::query()
            ->where('status', CashierSessionStatus::OPEN->value);

        $session = $cashierSessionId !== null
            ? $query->whereKey($cashierSessionId)->first()
            : $query->where('cashier_user_id', $cashierUserId)->first();

        return $session ?? throw new CashierSessionRequiredException;
    }
}
