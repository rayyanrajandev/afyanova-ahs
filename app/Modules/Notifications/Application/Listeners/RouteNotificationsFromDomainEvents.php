<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Laboratory\Domain\Events\LaboratoryOrderCompleted;
use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffAccepted;
use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffInitiated;
use App\Modules\Pharmacy\Domain\Events\PharmacyOrderDispensed;
use App\Modules\Radiology\Domain\Events\RadiologyOrderCompleted;
use App\Modules\ClinicalProcedure\Domain\Events\ClinicalProcedureOrderCompleted;
use App\Modules\Reception\Domain\Events\AppointmentCheckedIn;
use App\Modules\ServiceRequest\Domain\Events\ServiceRequestStatusChanged;
use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;

class RouteNotificationsFromDomainEvents
{
    private const APPOINTMENT_NOTIFIABLE_STATUSES = [
        'waiting_provider' => 'Patient ready for consultation',
        'checked_in' => 'Patient has arrived',
    ];

    public function __construct(
        private readonly DispatchInAppNotification $dispatchInAppNotification,
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
    ) {}

    public function handleAppointmentCheckedIn(AppointmentCheckedIn $event): void
    {
        $appointment = $this->appointmentRepository->findById($event->appointmentId);
        $clinicianUserId = $appointment['clinician_user_id'] ?? null;

        if ($clinicianUserId === null) {
            return;
        }

        $this->dispatchInAppNotification->handle(
            userId: $clinicianUserId,
            category: 'clinical',
            priority: 'normal',
            title: 'Patient checked in',
            body: sprintf('Patient #%s has arrived for their appointment.', $event->patientId),
            actionUrl: sprintf('/clinician/queue?focusAppointmentId=%s', $event->appointmentId),
            actionLabel: 'View queue',
            contextType: 'appointment',
            contextId: $event->appointmentId,
        );
    }

    public function handleAppointmentStatusChanged(AppointmentStatusChanged $event): void
    {
        $title = self::APPOINTMENT_NOTIFIABLE_STATUSES[$event->newStatus] ?? null;

        if ($title === null) {
            return;
        }

        $appointment = $this->appointmentRepository->findById($event->appointmentId);
        $clinicianUserId = $appointment['clinician_user_id'] ?? null;

        if ($clinicianUserId === null) {
            return;
        }

        $this->dispatchInAppNotification->handle(
            userId: $clinicianUserId,
            category: 'clinical',
            priority: 'normal',
            title: $title,
            body: sprintf('Appointment status changed from %s to %s for patient #%s.', $event->oldStatus, $event->newStatus, $event->patientId),
            actionUrl: sprintf('/clinician/queue?focusAppointmentId=%s', $event->appointmentId),
            actionLabel: 'View queue',
            contextType: 'appointment',
            contextId: $event->appointmentId,
        );
    }

    public function handleServiceRequestStatusChanged(ServiceRequestStatusChanged $event): void
    {
        if ($event->newStatus !== 'completed') {
            return;
        }

        $serviceRequest = $this->serviceRequestRepository->findById($event->serviceRequestId);
        $requestedByUserId = $serviceRequest['requested_by_user_id'] ?? null;

        if ($requestedByUserId === null) {
            return;
        }

        $this->dispatchInAppNotification->handle(
            userId: $requestedByUserId,
            category: 'clinical',
            priority: 'normal',
            title: 'Service request completed',
            body: sprintf('Service request #%s has been completed for patient #%s.', $event->serviceRequestId, $event->patientId),
            actionUrl: sprintf('/direct-service/queue?focusRequestId=%s', $event->serviceRequestId),
            actionLabel: 'View request',
            contextType: 'service_request',
            contextId: $event->serviceRequestId,
        );
    }

    public function handleLaboratoryOrderCompleted(LaboratoryOrderCompleted $event): void
    {
        if ($event->orderedByUserId === null) {
            return;
        }

        $this->dispatchInAppNotification->handle(
            userId: $event->orderedByUserId,
            category: 'laboratory',
            priority: 'normal',
            title: 'Laboratory result available',
            body: sprintf('Lab result has been completed for patient #%s.', $event->patientId),
            actionUrl: sprintf('/laboratory-orders?focusOrderId=%s', $event->laboratoryOrderId),
            actionLabel: 'View result',
            contextType: 'laboratory_order',
            contextId: $event->laboratoryOrderId,
        );
    }

    public function handlePharmacyOrderDispensed(PharmacyOrderDispensed $event): void
    {
        if ($event->orderedByUserId === null) {
            return;
        }

        $this->dispatchInAppNotification->handle(
            userId: $event->orderedByUserId,
            category: 'pharmacy',
            priority: 'normal',
            title: 'Medication dispensed',
            body: sprintf('Pharmacy order has been dispensed for patient #%s.', $event->patientId),
            actionUrl: sprintf('/pharmacy-orders?focusOrderId=%s', $event->pharmacyOrderId),
            actionLabel: 'View order',
            contextType: 'pharmacy_order',
            contextId: $event->pharmacyOrderId,
        );
    }

    public function handleRadiologyOrderCompleted(RadiologyOrderCompleted $event): void
    {
        if ($event->orderedByUserId === null) {
            return;
        }

        $this->dispatchInAppNotification->handle(
            userId: $event->orderedByUserId,
            category: 'clinical',
            priority: 'normal',
            title: 'Radiology report available',
            body: sprintf('Imaging study has been completed for patient #%s.', $event->patientId),
            actionUrl: sprintf('/radiology-orders?focusOrderId=%s', $event->radiologyOrderId),
            actionLabel: 'View report',
            contextType: 'radiology_order',
            contextId: $event->radiologyOrderId,
        );
    }

    public function handleClinicalProcedureOrderCompleted(ClinicalProcedureOrderCompleted $event): void
    {
        if ($event->orderedByUserId === null) {
            return;
        }

        $this->dispatchInAppNotification->handle(
            userId: $event->orderedByUserId,
            category: 'clinical',
            priority: 'normal',
            title: 'Procedure completed',
            body: sprintf('Clinical procedure has been completed for patient #%s.', $event->patientId),
            actionUrl: sprintf('/clinical-procedures?focusOrderId=%s', $event->clinicalProcedureOrderId),
            actionLabel: 'View procedure',
            contextType: 'clinical_procedure_order',
            contextId: $event->clinicalProcedureOrderId,
        );
    }

    public function handleMedicalRecordHandoffInitiated(MedicalRecordHandoffInitiated $event): void
    {
        $this->dispatchInAppNotification->handle(
            userId: $event->targetUserId,
            category: 'clinical',
            priority: 'high',
            title: 'Clinical note handed off to you',
            body: sprintf(
                '%s has handed off note %s to you. %s',
                $event->initiatorName,
                $event->recordNumber,
                $event->note ?? 'Please review and continue.',
            ),
            actionUrl: sprintf('/medical-records/%s/edit?handoff=accept', $event->medicalRecordId),
            actionLabel: 'Review note',
            contextType: 'medical_record',
            contextId: $event->medicalRecordId,
        );
    }

    public function handleMedicalRecordHandoffAccepted(MedicalRecordHandoffAccepted $event): void
    {
        $this->dispatchInAppNotification->handle(
            userId: $event->previousOwnerUserId,
            category: 'clinical',
            priority: 'normal',
            title: 'Handoff accepted',
            body: sprintf(
                '%s has accepted the handoff for note %s.',
                $event->newOwnerName,
                $event->recordNumber,
            ),
            actionUrl: sprintf('/medical-records/%s', $event->medicalRecordId),
            actionLabel: 'View note',
            contextType: 'medical_record',
            contextId: $event->medicalRecordId,
        );
    }
}
