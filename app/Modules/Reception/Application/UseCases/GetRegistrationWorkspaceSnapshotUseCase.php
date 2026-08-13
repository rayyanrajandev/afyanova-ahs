<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Application\UseCases\GetAppointmentUseCase;
use App\Modules\Patient\Application\UseCases\GetPatientSummaryUseCase;
use App\Modules\Patient\Application\UseCases\GetPatientUseCase;
use Carbon\Carbon;

class GetRegistrationWorkspaceSnapshotUseCase
{
    public function __construct(
        private readonly GetAppointmentUseCase $getAppointmentUseCase,
        private readonly GetPatientUseCase $getPatientUseCase,
        private readonly GetPatientSummaryUseCase $getPatientSummaryUseCase,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $appointmentId): ?array
    {
        $appointment = $this->getAppointmentUseCase->execute($appointmentId);
        if ($appointment === null) {
            return null;
        }

        $patientId = (string) ($appointment['patient_id'] ?? '');
        if ($patientId === '') {
            return [
                'patient' => null,
                'visit' => $appointment,
                'insurance' => null,
                'allergies' => [],
                'totalVisits' => 0,
                'outstandingInvoices' => 0,
                'firstRegisteredAt' => null,
                'lastVisitAt' => null,
                'recentActivity' => [],
            ];
        }

        $patient = $this->getPatientUseCase->execute($patientId);
        $summary = $this->getPatientSummaryUseCase->execute($patientId);

        $stats = is_array($summary['stats'] ?? null) ? $summary['stats'] : [];

        return [
            'patient' => $patient,
            'visit' => $appointment,
            'insurance' => $summary['insurance'] ?? null,
            'allergies' => $summary['allergies'] ?? [],
            'totalVisits' => (int) ($stats['totalVisits'] ?? 0),
            'outstandingInvoices' => (int) ($stats['outstandingInvoices'] ?? 0),
            'firstRegisteredAt' => $patient['created_at'] ?? null,
            'lastVisitAt' => $patient['last_visit_at'] ?? null,
            'recentActivity' => $summary['recentActivity'] ?? [],
            'patientAgeYears' => $this->calculateAgeYears($patient['date_of_birth'] ?? null),
        ];
    }

    private function calculateAgeYears(mixed $dateOfBirth): ?int
    {
        if (! is_string($dateOfBirth) || trim($dateOfBirth) === '') {
            return null;
        }

        try {
            return Carbon::parse($dateOfBirth)->age;
        } catch (\Throwable) {
            return null;
        }
    }
}
