<?php

namespace App\Modules\Appointment\Application\Support;

use Illuminate\Support\Carbon;

/**
 * Shared by Create/UpdateAppointmentUseCase's assertNoActiveSameDayConflict()
 * — previously both threw "Patient already has an active appointment
 * (...) on {datetime}{department}." regardless of the conflicting record's
 * actual status. That wording is only accurate while the conflict is still
 * `scheduled` (a real booking). Once it has progressed to waiting_triage/
 * waiting_provider/in_consultation, the patient has already arrived and is
 * being actively worked — describing that as "has an appointment" reads as
 * if nothing has happened yet, which is what prompted this fix. Appointment
 * stays the source of truth here (see the patient flow redesign's research:
 * Encounter has no "active encounter for patient" query today and no
 * operational queue reads it — rebasing this check onto Encounter is a
 * separate, larger effort, not a wording fix).
 */
class AppointmentConflictMessageFormatter
{
    /**
     * @param  array<string, mixed>  $existingAppointment
     */
    public static function activeSameDayConflict(array $existingAppointment): string
    {
        $appointmentNumber = (string) ($existingAppointment['appointment_number'] ?? 'existing appointment');
        $department = trim((string) ($existingAppointment['department'] ?? ''));
        $departmentPart = $department !== '' ? sprintf(' in %s', $department) : '';
        $status = strtolower((string) ($existingAppointment['status'] ?? ''));

        if ($status === 'scheduled') {
            $scheduledTime = isset($existingAppointment['scheduled_at'])
                ? Carbon::parse((string) $existingAppointment['scheduled_at'])->format('d M Y H:i')
                : null;
            $timePart = $scheduledTime !== null ? sprintf(' for %s', $scheduledTime) : '';

            return sprintf(
                'Patient already has an appointment (%s) scheduled%s%s.',
                $appointmentNumber,
                $timePart,
                $departmentPart,
            );
        }

        $arrivalTime = isset($existingAppointment['scheduled_at'])
            ? Carbon::parse((string) $existingAppointment['scheduled_at'])->format('d M Y H:i')
            : null;
        $sincePart = $arrivalTime !== null ? sprintf(' (since %s)', $arrivalTime) : '';

        $stateDescription = match ($status) {
            'in_consultation' => 'is currently in consultation',
            default => 'is already checked in and waiting to be seen', // waiting_triage, waiting_provider
        };

        return sprintf(
            'Patient %s%s%s — %s.',
            $stateDescription,
            $departmentPart,
            $sincePart,
            $appointmentNumber,
        );
    }

    /**
     * Non-blocking counterpart to activeSameDayConflict() above (2026-08-12,
     * Reception scheduling-duplicate audit) — used when
     * findOtherUpcomingForPatient() finds another unresolved appointment
     * *after* the new one has already been created, so the wording is
     * "heads up" rather than "blocked."
     *
     * @param  array<string, mixed>  $existingAppointment
     */
    public static function upcomingConflictWarning(array $existingAppointment): string
    {
        $appointmentNumber = (string) ($existingAppointment['appointment_number'] ?? 'existing appointment');
        $department = trim((string) ($existingAppointment['department'] ?? ''));
        $departmentPart = $department !== '' ? sprintf(' in %s', $department) : '';
        $status = strtolower((string) ($existingAppointment['status'] ?? ''));
        $scheduledTime = isset($existingAppointment['scheduled_at'])
            ? Carbon::parse((string) $existingAppointment['scheduled_at'])->format('d M Y H:i')
            : null;

        if ($status === 'scheduled') {
            $timePart = $scheduledTime !== null ? sprintf(' for %s', $scheduledTime) : '';

            return sprintf(
                'Patient also has another appointment (%s) scheduled%s%s.',
                $appointmentNumber,
                $timePart,
                $departmentPart,
            );
        }

        $sincePart = $scheduledTime !== null ? sprintf(' (since %s)', $scheduledTime) : '';
        $stateDescription = match ($status) {
            'in_consultation' => 'is currently in consultation',
            default => 'is already checked in and waiting to be seen', // waiting_triage, waiting_provider
        };

        return sprintf(
            'Patient also %s%s%s — %s.',
            $stateDescription,
            $departmentPart,
            $sincePart,
            $appointmentNumber,
        );
    }
}
