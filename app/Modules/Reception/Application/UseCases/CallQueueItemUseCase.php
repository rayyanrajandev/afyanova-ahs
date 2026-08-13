<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\Reception\Domain\Events\AppointmentCalled;

/**
 * Volume 2.1 §10.3 "Call" — see AppointmentCalled's own docblock for the
 * ephemeral-vs-persisted decision this implements. Deliberately does NOT
 * write anywhere: no status change, no PatientAuditLogRepositoryInterface
 * entry (unlike CheckInUseCase/CancelQueueItemUseCase, which both
 * correctly do — a call has no clinical or record-keeping weight to
 * capture). Read the appointment/patient, fire the broadcast, done.
 */
class CallQueueItemUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly PatientRepositoryInterface $patientRepository,
    ) {}

    /**
     * @return array<string, mixed>|null null if the appointment (or its
     *   patient) doesn't exist — the controller maps this to a 404, same
     *   convention as CheckInUseCase/CancelQueueItemUseCase.
     */
    public function execute(string $appointmentId): ?array
    {
        $appointment = $this->appointmentRepository->findById($appointmentId);
        if ($appointment === null) {
            return null;
        }

        $patient = $this->patientRepository->findById((string) $appointment['patient_id']);
        if ($patient === null) {
            return null;
        }

        $patientName = trim(
            ($patient['first_name'] ?? '').' '.($patient['last_name'] ?? ''),
        );

        event(new AppointmentCalled(
            facilityId: (string) $appointment['facility_id'],
            appointmentId: $appointmentId,
            patientName: $patientName,
        ));

        return $appointment;
    }
}
