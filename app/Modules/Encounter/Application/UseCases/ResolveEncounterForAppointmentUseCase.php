<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\MedicalRecord\Application\Exceptions\AppointmentNotEligibleForMedicalRecordException;
use App\Modules\MedicalRecord\Domain\Services\AppointmentLookupServiceInterface;

class ResolveEncounterForAppointmentUseCase
{
    public function __construct(
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly EncounterResolverService $encounterResolverService,
        private readonly GetEncounterWorkspaceUseCase $getEncounterWorkspaceUseCase,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $appointmentId, ?int $actorId, bool $includeWorkspace = false): ?array
    {
        $appointment = $this->appointmentLookupService->findById($appointmentId);
        if ($appointment === null) {
            return null;
        }

        $patientId = trim((string) ($appointment['patient_id'] ?? ''));
        if ($patientId === '') {
            throw new AppointmentNotEligibleForMedicalRecordException(
                'Appointment must be linked to a patient before opening an encounter.',
            );
        }

        $encounter = $this->encounterResolverService->findOrCreateForVisit(
            patientId: $patientId,
            appointmentId: $appointmentId,
            admissionId: null,
            actorId: $actorId,
        );

        if ($includeWorkspace) {
            return $this->getEncounterWorkspaceUseCase->execute((string) $encounter->id);
        }

        return [
            'encounter' => $encounter->toArray(),
            'appointment' => $appointment,
            'primaryMedicalRecord' => null,
            'laboratoryOrders' => [],
            'pharmacyOrders' => [],
            'radiologyOrders' => [],
            'theatreProcedures' => [],
        ];
    }
}
