<?php

namespace App\Modules\Reception;

use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Reception\Application\Listeners\CreateSkeletonEmergencyTriageCase;
use App\Modules\Reception\Application\Listeners\LogShadowEmergencyTriageCaseCreation;
use App\Modules\Reception\Application\Listeners\ResolveSkeletonEmergencyTriageCaseOnAppointmentClosure;
use App\Modules\Reception\Domain\Events\AppointmentCheckedIn;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ReceptionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Mode B (shadow log, always active) and Mode C (opt-in, disabled by
        // default — see config/reception_automation.php) both listen
        // independently. Mode B keeps logging its "would create" prediction
        // even once Mode C is enabled and actually creates the record —
        // deliberately unmodified, so its trend data stays comparable across
        // the mode transition rather than changing meaning partway through.
        Event::listen(AppointmentCheckedIn::class, LogShadowEmergencyTriageCaseCreation::class);
        Event::listen(AppointmentCheckedIn::class, CreateSkeletonEmergencyTriageCase::class);

        // Mode C's counterpart (2026-08-12 bug fix) — resolves the skeleton
        // case CreateSkeletonEmergencyTriageCase created once its linked
        // appointment is cancelled/completed, so it stops permanently
        // blocking the patient from a future visit. Same config flag: if
        // Mode C never created it, there's nothing for this to resolve.
        Event::listen(AppointmentStatusChanged::class, ResolveSkeletonEmergencyTriageCaseOnAppointmentClosure::class);
    }
}
