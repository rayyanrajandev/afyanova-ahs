<?php

namespace App\Modules\Billing\Infrastructure\Services;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Billing\Domain\Services\AppointmentLookupServiceInterface;

class AppointmentLookupService implements AppointmentLookupServiceInterface
{
    /**
     * @var list<string>
     */
    private const ACTIVE_BILLING_APPOINTMENT_STATUSES = [
        'checked_in',
        'waiting_triage',
        'waiting_provider',
        'in_consultation',
    ];

    public function __construct(private readonly AppointmentRepositoryInterface $appointmentRepository) {}

    public function isValidForPatient(string $appointmentId, string $patientId): bool
    {
        $appointment = $this->findById($appointmentId);

        return $appointment !== null && ($appointment['patient_id'] ?? null) === $patientId;
    }

    public function findById(string $appointmentId): ?array
    {
        return $this->appointmentRepository->findById($appointmentId);
    }

    public function findSingleActiveBillingAppointmentForPatient(string $patientId): ?array
    {
        $result = $this->appointmentRepository->search(
            query: null,
            patientId: $patientId,
            clinicianUserId: null,
            department: null,
            unassignedClinicianOnly: false,
            status: null,
            triageCategory: null,
            fromDateTime: null,
            toDateTime: null,
            page: 1,
            perPage: 25,
            sortBy: 'scheduled_at',
            sortDirection: 'desc',
        );

        $activeAppointments = array_values(array_filter(
            $result['data'] ?? [],
            fn (array $appointment): bool => in_array(
                strtolower(trim((string) ($appointment['status'] ?? ''))),
                self::ACTIVE_BILLING_APPOINTMENT_STATUSES,
                true,
            ),
        ));

        return count($activeAppointments) === 1 ? $activeAppointments[0] : null;
    }
}
