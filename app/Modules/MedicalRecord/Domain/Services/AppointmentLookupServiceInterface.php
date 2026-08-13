<?php

namespace App\Modules\MedicalRecord\Domain\Services;

interface AppointmentLookupServiceInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $appointmentId): ?array;

    public function isValidForPatient(string $appointmentId, string $patientId): bool;
}
