<?php

namespace App\Modules\EmergencyTriage\Domain\Services;

interface AppointmentLookupServiceInterface
{
    public function isValidForPatient(string $appointmentId, string $patientId): bool;
}
