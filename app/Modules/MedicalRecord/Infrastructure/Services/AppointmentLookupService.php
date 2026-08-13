<?php

namespace App\Modules\MedicalRecord\Infrastructure\Services;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Services\AppointmentLookupServiceInterface;

class AppointmentLookupService implements AppointmentLookupServiceInterface
{
    public function __construct(private readonly AppointmentRepositoryInterface $appointmentRepository) {}

    public function findById(string $appointmentId): ?array
    {
        return $this->appointmentRepository->findById($appointmentId);
    }

    public function isValidForPatient(string $appointmentId, string $patientId): bool
    {
        $appointment = $this->findById($appointmentId);

        return $appointment !== null && ($appointment['patient_id'] ?? null) === $patientId;
    }
}
