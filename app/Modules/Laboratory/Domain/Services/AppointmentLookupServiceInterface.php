<?php

namespace App\Modules\Laboratory\Domain\Services;

interface AppointmentLookupServiceInterface
{
    public function isValidForPatient(string $appointmentId, string $patientId): bool;
}
