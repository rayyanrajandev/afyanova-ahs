<?php

namespace App\Modules\MedicalRecord\Domain\Services;

interface AppointmentReferralLookupServiceInterface
{
    public function findByAppointment(string $appointmentId, string $appointmentReferralId): ?array;
}
