<?php

namespace App\Modules\ClinicalProcedure\Infrastructure\Services;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\ClinicalProcedure\Domain\Services\AppointmentLookupServiceInterface;

class AppointmentLookupService implements AppointmentLookupServiceInterface
{
    public function __construct(private readonly AppointmentRepositoryInterface $appointmentRepository) {}

    public function isValidForPatient(string $appointmentId, string $patientId): bool
    {
        $appointment = $this->appointmentRepository->findById($appointmentId);

        return $appointment !== null && ($appointment['patient_id'] ?? null) === $patientId;
    }
}
