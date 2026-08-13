<?php

namespace App\Modules\ClinicalProcedure\Domain\Services;

interface AppointmentLookupServiceInterface
{
    public function isValidForPatient(string $appointmentId, string $patientId): bool;
}
