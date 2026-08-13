<?php

namespace App\Modules\Admission\Domain\Services;

interface AppointmentLookupServiceInterface
{
    public function isValidForPatient(string $appointmentId, string $patientId): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $appointmentId): ?array;
}
