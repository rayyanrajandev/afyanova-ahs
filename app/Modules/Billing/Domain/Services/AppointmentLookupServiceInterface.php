<?php

namespace App\Modules\Billing\Domain\Services;

interface AppointmentLookupServiceInterface
{
    public function isValidForPatient(string $appointmentId, string $patientId): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $appointmentId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findSingleActiveBillingAppointmentForPatient(string $patientId): ?array;
}
