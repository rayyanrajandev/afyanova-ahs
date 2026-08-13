<?php

namespace App\Modules\Radiology\Infrastructure\Services;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Radiology\Domain\Services\AppointmentLookupServiceInterface;

class AppointmentLookupService implements AppointmentLookupServiceInterface
{
    public function __construct(private readonly AppointmentRepositoryInterface $appointmentRepository) {}

    public function isValidForPatient(string $appointmentId, string $patientId): bool
    {
        $appointment = $this->appointmentRepository->findById($appointmentId);

        return $appointment !== null && ($appointment['patient_id'] ?? null) === $patientId;
    }
}
