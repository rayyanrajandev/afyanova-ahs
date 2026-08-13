<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;

class AppointmentPolicy
{
    public function reschedule(User $user, AppointmentModel $appointment): bool
    {
        if (! $user->hasPermissionTo('appointment.reschedule')) {
            return false;
        }

        return ! in_array($appointment->status, [
            AppointmentStatus::COMPLETED->value,
            AppointmentStatus::CANCELLED->value,
            AppointmentStatus::NO_SHOW->value,
        ], true);
    }

    public function cancel(User $user, AppointmentModel $appointment): bool
    {
        if (! $user->hasPermissionTo('appointment.cancel')) {
            return false;
        }

        return ! in_array($appointment->status, [
            AppointmentStatus::COMPLETED->value,
            AppointmentStatus::CANCELLED->value,
            AppointmentStatus::NO_SHOW->value,
        ], true);
    }

    public function checkIn(User $user, AppointmentModel $appointment): bool
    {
        if (! $user->hasPermissionTo('appointment.check-in')) {
            return false;
        }

        return $appointment->status === AppointmentStatus::SCHEDULED->value;
    }

    public function checkOut(User $user, AppointmentModel $appointment): bool
    {
        if (! $user->hasPermissionTo('appointment.check-out')) {
            return false;
        }

        return $appointment->status === AppointmentStatus::IN_CONSULTATION->value;
    }
}
