<?php

namespace App\Modules\Appointment\Application\Listeners;

use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Revenue\Domain\Events\ServiceChargeAuthorized;
use App\Modules\Revenue\Domain\ValueObjects\ChargeSourceKind;
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
        } catch (Throwable $exception) {
            // The money is already taken and the charge is already cleared.
            // Failing to advance the queue must not undo either, and reception
            // can still check the patient in by hand.
            Log::warning('Unable to promote an appointment after its charge cleared.', [
                'appointment_id' => $event->sourceId,
                'service_charge_id' => $event->serviceChargeId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
