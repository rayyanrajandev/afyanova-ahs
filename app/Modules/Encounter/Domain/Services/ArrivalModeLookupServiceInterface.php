<?php

namespace App\Modules\Encounter\Domain\Services;

interface ArrivalModeLookupServiceInterface
{
    /**
     * The most recent Reception-recorded arrival mode for this appointment
     * (Reception\Domain\ValueObjects\ArrivalMode value), or null if the
     * appointment has no arrival event (e.g. resolved before Reception's
     * check-in flow existed).
     */
    public function findLatestForAppointment(string $appointmentId): ?string;
}
