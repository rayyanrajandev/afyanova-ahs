<?php

namespace App\Modules\Notifications;

use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Laboratory\Domain\Events\LaboratoryOrderCompleted;
use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffAccepted;
use App\Modules\MedicalRecord\Domain\Events\MedicalRecordHandoffInitiated;
use App\Modules\Notifications\Application\Listeners\RouteNotificationsFromDomainEvents;
use App\Modules\Pharmacy\Domain\Events\PharmacyOrderDispensed;
use App\Modules\Radiology\Domain\Events\RadiologyOrderCompleted;
use App\Modules\ClinicalProcedure\Domain\Events\ClinicalProcedureOrderCompleted;
use App\Modules\Reception\Domain\Events\AppointmentCheckedIn;
use App\Modules\ServiceRequest\Domain\Events\ServiceRequestStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(
            AppointmentCheckedIn::class,
            [RouteNotificationsFromDomainEvents::class, 'handleAppointmentCheckedIn'],
        );
        Event::listen(
            AppointmentStatusChanged::class,
            [RouteNotificationsFromDomainEvents::class, 'handleAppointmentStatusChanged'],
        );
        Event::listen(
            ServiceRequestStatusChanged::class,
            [RouteNotificationsFromDomainEvents::class, 'handleServiceRequestStatusChanged'],
        );
        Event::listen(
            LaboratoryOrderCompleted::class,
            [RouteNotificationsFromDomainEvents::class, 'handleLaboratoryOrderCompleted'],
        );
        Event::listen(
            PharmacyOrderDispensed::class,
            [RouteNotificationsFromDomainEvents::class, 'handlePharmacyOrderDispensed'],
        );
        Event::listen(
            RadiologyOrderCompleted::class,
            [RouteNotificationsFromDomainEvents::class, 'handleRadiologyOrderCompleted'],
        );
        Event::listen(
            ClinicalProcedureOrderCompleted::class,
            [RouteNotificationsFromDomainEvents::class, 'handleClinicalProcedureOrderCompleted'],
        );
        Event::listen(
            MedicalRecordHandoffInitiated::class,
            [RouteNotificationsFromDomainEvents::class, 'handleMedicalRecordHandoffInitiated'],
        );
        Event::listen(
            MedicalRecordHandoffAccepted::class,
            [RouteNotificationsFromDomainEvents::class, 'handleMedicalRecordHandoffAccepted'],
        );
    }
}
