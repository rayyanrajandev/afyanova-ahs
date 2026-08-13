<?php

namespace App\Modules\Admission\Infrastructure\Services;

use App\Modules\Admission\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;

class AppointmentLookupService implements AppointmentLookupServiceInterface
{
    public function __construct(private readonly AppointmentRepositoryInterface $appointmentRepository) {}

    public function isValidForPatient(string $appointmentId, string $patientId): bool
    {
        $appointment = $this->findById($appointmentId);

        return $appointment !== null && ($appointment['patient_id'] ?? null) === $patientId;
    }

    public function findById(string $appointmentId): ?array
    {
        return $this->appointmentRepository->findById($appointmentId);
    }
}
