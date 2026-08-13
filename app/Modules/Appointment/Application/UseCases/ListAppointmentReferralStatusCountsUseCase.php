<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Domain\Repositories\AppointmentReferralRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralPriority;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralType;

class ListAppointmentReferralStatusCountsUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentReferralRepositoryInterface $referralRepository,
    ) {}

    public function execute(string $appointmentId, array $filters): ?array
    {
        $appointment = $this->appointmentRepository->findById($appointmentId);
        if (! $appointment) {
            return null;
        }

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $referralType = isset($filters['referralType']) ? strtolower(trim((string) $filters['referralType'])) : null;
        if (! in_array($referralType, AppointmentReferralType::values(), true)) {
            $referralType = null;
        }

        $priority = isset($filters['priority']) ? strtolower(trim((string) $filters['priority'])) : null;
        if (! in_array($priority, AppointmentReferralPriority::values(), true)) {
            $priority = null;
        }

        $targetFacilityCode = isset($filters['targetFacilityCode'])
            ? strtoupper(trim((string) $filters['targetFacilityCode']))
            : null;
        $targetFacilityCode = $targetFacilityCode === '' ? null : $targetFacilityCode;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->referralRepository->statusCountsByAppointment(
            appointmentId: $appointmentId,
            query: $query,
            referralType: $referralType,
            priority: $priority,
            targetFacilityCode: $targetFacilityCode,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
