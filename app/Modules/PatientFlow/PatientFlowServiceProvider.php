<?php

namespace App\Modules\PatientFlow;

use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Laboratory\Domain\Events\LaboratoryOrderCompleted;
use App\Modules\PatientFlow\Application\Listeners\BroadcastPatientFlowBoardUpdate;
use App\Modules\PatientFlow\Application\Listeners\LogOrderCompletionForOrderingClinician;
use App\Modules\Pharmacy\Domain\Events\PharmacyOrderDispensed;
use App\Modules\Radiology\Domain\Events\RadiologyOrderCompleted;
use App\Modules\ClinicalProcedure\Domain\Events\ClinicalProcedureOrderCompleted;
use App\Modules\Reception\Domain\Events\AppointmentCheckedIn;
use App\Modules\ServiceRequest\Domain\Events\ServiceRequestStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PatientFlowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Mode A (shadow log, always active) of
        // reports/queue-based-workflow-modernization-plan.md §3.3 — one
        // listener, three events, one method per event since the three
        // Domain\Events classes share no common interface to dispatch on.
        Event::listen(
            LaboratoryOrderCompleted::class,
            [LogOrderCompletionForOrderingClinician::class, 'handleLaboratoryOrderCompleted'],
        );
        Event::listen(
            PharmacyOrderDispensed::class,
            [LogOrderCompletionForOrderingClinician::class, 'handlePharmacyOrderDispensed'],
        );
        Event::listen(
            RadiologyOrderCompleted::class,
            [LogOrderCompletionForOrderingClinician::class, 'handleRadiologyOrderCompleted'],
        );
        Event::listen(
            ClinicalProcedureOrderCompleted::class,
            [LogOrderCompletionForOrderingClinician::class, 'handleClinicalProcedureOrderCompleted'],
        );

        // Patient-Flow Board Phase 2/3 — translates 6 cross-module domain
        // events into the board's own single PatientFlowBoardUpdated
        // broadcast (see BroadcastPatientFlowBoardUpdate's docblock).
        Event::listen(
            AppointmentCheckedIn::class,
            [BroadcastPatientFlowBoardUpdate::class, 'handleAppointmentCheckedIn'],
        );
        Event::listen(
            AppointmentStatusChanged::class,
            [BroadcastPatientFlowBoardUpdate::class, 'handleAppointmentStatusChanged'],
        );
        Event::listen(
            LaboratoryOrderCompleted::class,
            [BroadcastPatientFlowBoardUpdate::class, 'handleLaboratoryOrderCompleted'],
        );
        Event::listen(
            PharmacyOrderDispensed::class,
            [BroadcastPatientFlowBoardUpdate::class, 'handlePharmacyOrderDispensed'],
        );
        Event::listen(
            RadiologyOrderCompleted::class,
            [BroadcastPatientFlowBoardUpdate::class, 'handleRadiologyOrderCompleted'],
        );
        Event::listen(
            ClinicalProcedureOrderCompleted::class,
            [BroadcastPatientFlowBoardUpdate::class, 'handleClinicalProcedureOrderCompleted'],
        );
        Event::listen(
            ServiceRequestStatusChanged::class,
            [BroadcastPatientFlowBoardUpdate::class, 'handleServiceRequestStatusChanged'],
        );
    }
}
