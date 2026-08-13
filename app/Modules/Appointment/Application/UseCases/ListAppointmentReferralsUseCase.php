<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Domain\Repositories\AppointmentReferralRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralPriority;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralStatus;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentReferralType;

class ListAppointmentReferralsUseCase
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

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $referralType = isset($filters['referralType']) ? strtolower(trim((string) $filters['referralType'])) : null;
        if (! in_array($referralType, AppointmentReferralType::values(), true)) {
            $referralType = null;
        }

        $priority = isset($filters['priority']) ? strtolower(trim((string) $filters['priority'])) : null;
        if (! in_array($priority, AppointmentReferralPriority::values(), true)) {
            $priority = null;
        }

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        if (! in_array($status, AppointmentReferralStatus::values(), true)) {
            $status = null;
        }

        $targetFacilityCode = isset($filters['targetFacilityCode'])
            ? strtoupper(trim((string) $filters['targetFacilityCode']))
            : null;
        $targetFacilityCode = $targetFacilityCode === '' ? null : $targetFacilityCode;

        $sortMap = [
            'referralNumber' => 'referral_number',
            'referralType' => 'referral_type',
            'priority' => 'priority',
            'requestedAt' => 'requested_at',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'requestedAt';
        $sortBy = $sortMap[$sortBy] ?? 'requested_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->referralRepository->searchByAppointment(
            appointmentId: $appointmentId,
            query: $query,
            referralType: $referralType,
            priority: $priority,
            status: $status,
            targetFacilityCode: $targetFacilityCode,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
