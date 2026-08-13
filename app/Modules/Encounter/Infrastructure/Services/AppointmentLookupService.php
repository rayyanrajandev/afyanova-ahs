<?php

namespace App\Modules\Encounter\Infrastructure\Services;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Encounter\Domain\Services\AppointmentLookupServiceInterface;

class AppointmentLookupService implements AppointmentLookupServiceInterface
{
    public function __construct(private readonly AppointmentRepositoryInterface $appointmentRepository) {}

    public function findById(string $appointmentId): ?array
    {
        return $this->appointmentRepository->findById($appointmentId);
    }
}
