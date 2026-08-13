<?php

namespace App\Modules\MedicalRecord\Infrastructure\Services;

use App\Modules\Appointment\Domain\Repositories\AppointmentReferralRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Services\AppointmentReferralLookupServiceInterface;

class AppointmentReferralLookupService implements AppointmentReferralLookupServiceInterface
{
    public function __construct(
        private readonly AppointmentReferralRepositoryInterface $appointmentReferralRepository,
    ) {}

    public function findByAppointment(string $appointmentId, string $appointmentReferralId): ?array
    {
        return $this->appointmentReferralRepository->findByAppointmentAndId(
            $appointmentId,
            $appointmentReferralId,
        );
    }
}
