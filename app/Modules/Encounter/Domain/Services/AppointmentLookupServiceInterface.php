<?php

namespace App\Modules\Encounter\Domain\Services;

interface AppointmentLookupServiceInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $appointmentId): ?array;
}
