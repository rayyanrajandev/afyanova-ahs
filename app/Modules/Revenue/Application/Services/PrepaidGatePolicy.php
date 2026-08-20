<?php

namespace App\Modules\Revenue\Application\Services;

use App\Modules\Revenue\Application\UseCases\CancelServiceChargeUseCase;
use App\Modules\Revenue\Domain\Services\RevenueTelemetryRecorderInterface;
use App\Modules\Revenue\Domain\Services\ServiceAuthorizationReaderInterface;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryEvent;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryReason;
use App\Modules\Revenue\Domain\ValueObjects\ServiceChargeStatus;
use App\Modules\Revenue\Infrastructure\Models\ServiceChargeModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The prepaid rule for clinically ordered services, in one place.
 *
 * It existed in four: Laboratory, Radiology, Pharmacy and Clinical Procedure
 * each carried their own copy of "is this paid for?" and their own copy of
 * "cancel the charge when the order is cancelled". The copies had already
 * drifted into three different shapes with four different failure behaviours
 * (2026-08-19 workspace maturity audit, finding C3) — the same duplication
 * disease PatientFlowStep was created to cure, one module over.
 *
 * The split of responsibility is deliberate:
 *
 *  - **This class owns the mechanism** — when the gate applies, how the ledger
 *    is read, what happens when Revenue fails.
 *  - **The module owns its vocabulary** — which of *its* statuses mean the
 *    service is actually being delivered, and what to tell a clinician who is
 *    refused. Neither belongs in Revenue: a radiographer's language is not a
 *    ledger's, and Revenue has no business enumerating another module's enum.
 *
 * Statuses are declared as an allowlist, not "everything except cancelled".
 * The denylist three of the four modules used gates every status it has not
 * heard of, so adding a state to an order enum silently made it payment-gated —
 * failing closed in the one direction a clinical system must not.
 */
final class PrepaidGatePolicy
{
    public function __construct(
        private readonly ServiceAuthorizationReaderInterface $authorizationReader,
        private readonly CancelServiceChargeUseCase $cancelServiceCharge,
        private readonly RevenueTelemetryRecorderInterface $telemetry,
    ) {}

    /**
     * Refuse a transition that would deliver an unpaid service.
     *
     * A blocked order is the gate working, not an anomaly, so nothing is
     * recorded here — the clinician is told, and that is the whole event.
     *
     * @param  array<int, string>  $deliveryStatuses  The module's own statuses that mean
     *                                                the service is being provided.
     *
     * @throws ValidationException
     */
    public function assertAuthorized(
        ChargeSourceKind $kind,
        string $orderId,
        string $targetStatus,
        array $deliveryStatuses,
        string $refusalMessage,
    ): void {
        if (! in_array($targetStatus, $deliveryStatuses, true)) {
            return;
        }

        if (! $kind->prepaidGateEnabled()) {
            return;
        }

        if ($this->authorizationReader->isAuthorized($kind, $orderId)) {
            return;
        }

        throw ValidationException::withMessages(['status' => $refusalMessage]);
    }

    /**
     * Cancel a charge the patient has not paid, so nobody is billed for a
     * service that will never be provided.
     *
     * Never rethrows, and never swallows. A Revenue failure must not undo a
     * clinical decision the clinician has already made — but it must not vanish
     * either: the charge is still live, and reconciling it is impossible if the
     * only record is an empty catch block.
     */
    public function cancelPendingCharge(
        ChargeSourceKind $kind,
        string $orderId,
        string $reason,
        ?int $actorId,
        string $fallbackReason,
    ): void {
        $charge = null;

        try {
            $charge = ServiceChargeModel::query()
                ->where('source_workflow_kind', $kind->value)
                ->where('source_workflow_id', $orderId)
                ->whereIn('status', [
                    ServiceChargeStatus::DRAFT->value,
                    ServiceChargeStatus::PENDING_PAYMENT->value,
                ])
                ->first();

            if ($charge === null) {
                return;
            }

            // CancelServiceChargeUseCase refuses an empty reason and types
            // $actorUserId as a non-nullable int, so both are normalised here
            // rather than letting a console or system actor raise a TypeError.
            $this->cancelServiceCharge->execute(
                (string) $charge->id,
                trim($reason) !== '' ? trim($reason) : $fallbackReason,
                (int) ($actorId ?? 0),
            );
        } catch (Throwable $exception) {
            Log::warning('Unable to cancel the pending charge for a cancelled '.$kind->label().'.', [
                'order_id' => $orderId,
                'charge_id' => $charge === null ? null : (string) $charge->id,
                'source_kind' => $kind->value,
                'error' => $exception->getMessage(),
            ]);

            $this->telemetry->record(
                event: RevenueTelemetryEvent::CHARGE_CANCEL_FAILED,
                reason: RevenueTelemetryReason::EXCEPTION,
                sourceKind: $kind,
                sourceWorkflowId: $orderId,
                serviceChargeId: $charge === null ? null : (string) $charge->id,
                actorUserId: $actorId,
                detail: $exception->getMessage(),
            );
        }
    }
}
