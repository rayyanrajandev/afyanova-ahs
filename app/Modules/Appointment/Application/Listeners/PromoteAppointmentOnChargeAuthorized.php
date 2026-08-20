<?php

namespace App\Modules\Appointment\Application\Listeners;

use App\Modules\Appointment\Application\UseCases\RecordAppointmentTriageUseCase;
use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\PatientVitals\Application\Services\VitalsSummaryFormatter;
use App\Modules\PatientVitals\Infrastructure\Models\PatientVitalSetModel;
use App\Modules\Revenue\Domain\Events\ServiceChargeAuthorized;
use App\Modules\Revenue\Domain\Services\RevenueTelemetryRecorderInterface;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryEvent;
use App\Modules\Revenue\Domain\ValueObjects\RevenueTelemetryReason;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves a patient out of the cashier queue the moment their consultation
 * charge clears.
 *
 * Without this, a patient who has just paid would sit in AWAITING_PAYMENT
 * until reception noticed and checked them in a second time. The cashier is
 * the one who knows the money arrived, but promoting the visit is not the
 * cashier's job — so Revenue announces, and Appointment reacts.
 *
 * Only ever promotes from AWAITING_PAYMENT. A charge cleared before the
 * patient arrives leaves the visit SCHEDULED, and check-in will route it
 * straight to triage.
 */
class PromoteAppointmentOnChargeAuthorized
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly UpdateAppointmentStatusUseCase $updateAppointmentStatus,
        private readonly RevenueTelemetryRecorderInterface $telemetry,
        private readonly RecordAppointmentTriageUseCase $recordTriage,
        private readonly VitalsSummaryFormatter $vitalsSummary,
    ) {}

    public function handle(ServiceChargeAuthorized $event): void
    {
        if ($event->sourceKind !== ChargeSourceKind::CONSULTATION || $event->sourceId === null) {
            return;
        }

        try {
            $appointment = $this->appointmentRepository->findById($event->sourceId);

            if ($appointment === null) {
                return;
            }

            $status = strtolower(trim((string) ($appointment['status'] ?? '')));

            if ($status !== AppointmentStatus::AWAITING_PAYMENT->value) {
                return;
            }

            $this->updateAppointmentStatus->execute(
                id: $event->sourceId,
                status: AppointmentStatus::WAITING_TRIAGE->value,
                reason: null,
                actorId: $event->actorUserId,
            );

            $this->completeDeferredTriage($event->sourceId, $event->actorUserId);
        } catch (Throwable $exception) {
            // The money is already taken and the charge is already cleared.
            // Failing to advance the queue must not undo either, and reception
            // can still check the patient in by hand.
            Log::warning('Unable to promote an appointment after its charge cleared.', [
                'appointment_id' => $event->sourceId,
                'service_charge_id' => $event->serviceChargeId,
                'error' => $exception->getMessage(),
            ]);

            // The patient has paid and is still sitting in AWAITING_PAYMENT.
            // Nobody at the counter or the desk can see that from here, so it
            // is recorded as an anomaly a reconciliation can surface.
            $this->telemetry->record(
                event: RevenueTelemetryEvent::PROMOTION_FAILED,
                reason: RevenueTelemetryReason::EXCEPTION,
                sourceKind: ChargeSourceKind::CONSULTATION,
                sourceWorkflowId: $event->sourceId,
                serviceChargeId: $event->serviceChargeId,
                actorUserId: $event->actorUserId,
                detail: $exception->getMessage(),
            );
        }
    }

    /**
     * Finish a triage handoff that was deferred because the visit was unpaid.
     *
     * Recording observations is what normally advances a visit out of
     * waiting_triage. Under the prepaid rule a nurse may take them before the
     * charge clears — and should, because care is not gated on billing — but
     * the handoff is held back. Nothing then resumed it: the visit was promoted
     * to waiting_triage on payment and sat there with vitals already on file,
     * while every queue asked for the vitals a nurse had already taken.
     *
     * Best-effort by the same reasoning as the promotion above: the money has
     * cleared, and a failure to advance the queue must not undo that.
     */
    private function completeDeferredTriage(string $appointmentId, ?int $actorId): void
    {
        $vitals = PatientVitalSetModel::query()
            ->where('appointment_id', $appointmentId)
            ->where('entry_state', 'active')
            ->latest('recorded_at')
            ->first();

        if ($vitals === null) {
            return;
        }

        try {
            $this->recordTriage->execute(
                id: $appointmentId,
                triageVitalsSummary: $this->vitalsSummary->fromRecord($vitals),
                triageNotes: null,
                // Recording observations is not the moment a nurse routes a
                // patient, and this is later still — the routing the visit
                // already carries stands.
                requireRouting: false,
                actorId: $actorId,
            );
        } catch (Throwable $exception) {
            Log::warning('Unable to complete a deferred triage handoff after payment cleared.', [
                'appointment_id' => $appointmentId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
