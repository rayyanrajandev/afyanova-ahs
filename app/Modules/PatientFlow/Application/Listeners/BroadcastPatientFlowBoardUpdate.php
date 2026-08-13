<?php

namespace App\Modules\PatientFlow\Application\Listeners;

use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Laboratory\Domain\Events\LaboratoryOrderCompleted;
use App\Modules\PatientFlow\Domain\Events\PatientFlowBoardUpdated;
use App\Modules\Pharmacy\Domain\Events\PharmacyOrderDispensed;
use App\Modules\Radiology\Domain\Events\RadiologyOrderCompleted;
use App\Modules\ClinicalProcedure\Domain\Events\ClinicalProcedureOrderCompleted;
use App\Modules\Reception\Domain\Events\AppointmentCheckedIn;
use App\Modules\ServiceRequest\Domain\Events\ServiceRequestStatusChanged;

/**
 * Patient-Flow Board Phase 2/3: translates 6 cross-module domain events into
 * the board's own single PatientFlowBoardUpdated broadcast — mirrors this
 * module's existing charter (LogOrderCompletionForOrderingClinician already
 * turns 3 of these same events into one Patient-Flow-owned concern, logging
 * instead of broadcasting). A thin translator: each handler only extracts
 * facilityId and re-dispatches, no other logic.
 */
class BroadcastPatientFlowBoardUpdate
{
    public function handleAppointmentCheckedIn(AppointmentCheckedIn $event): void
    {
        event(new PatientFlowBoardUpdated($event->facilityId));
    }

    public function handleAppointmentStatusChanged(AppointmentStatusChanged $event): void
    {
        event(new PatientFlowBoardUpdated($event->facilityId));
    }

    public function handleLaboratoryOrderCompleted(LaboratoryOrderCompleted $event): void
    {
        event(new PatientFlowBoardUpdated($event->facilityId));
    }

    public function handlePharmacyOrderDispensed(PharmacyOrderDispensed $event): void
    {
        event(new PatientFlowBoardUpdated($event->facilityId));
    }

    public function handleRadiologyOrderCompleted(RadiologyOrderCompleted $event): void
    {
        event(new PatientFlowBoardUpdated($event->facilityId));
    }

    public function handleClinicalProcedureOrderCompleted(ClinicalProcedureOrderCompleted $event): void
    {
        event(new PatientFlowBoardUpdated($event->facilityId));
    }

    public function handleServiceRequestStatusChanged(ServiceRequestStatusChanged $event): void
    {
        event(new PatientFlowBoardUpdated($event->facilityId));
    }
}
