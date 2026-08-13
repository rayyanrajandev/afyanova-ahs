<?php

namespace App\Console\Commands;

use App\Modules\Appointment\Infrastructure\Models\AppointmentModel;
use App\Modules\Notifications\Application\Listeners\DispatchInAppNotification;
use Illuminate\Console\Command;

class NotifyCliniciansOfWaitingPatients extends Command
{
    protected $signature = 'notifications:sweep-waiting-patients {--dry-run : Show what would be created without inserting}';

    protected $description = 'Create notifications for clinicians who have patients waiting for them in the queue';

    private const NOTIFIABLE_STATUSES = ['waiting_provider', 'checked_in', 'waiting_triage'];

    public function handle(DispatchInAppNotification $dispatch): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $appointments = AppointmentModel::query()
            ->whereIn('status', self::NOTIFIABLE_STATUSES)
            ->whereNotNull('clinician_user_id')
            ->orderBy('updated_at')
            ->get();

        if ($appointments->isEmpty()) {
            $this->info('No waiting patients with assigned clinicians found.');
            return 0;
        }

        $this->line(sprintf('Found %d waiting patient(s) with assigned clinicians.', $appointments->count()));

        $created = 0;
        $skipped = 0;

        foreach ($appointments as $appointment) {
            $exists = \App\Modules\Notifications\Infrastructure\Models\NotificationModel::where('user_id', $appointment->clinician_user_id)
                ->where('context_type', 'appointment')
                ->where('context_id', $appointment->id)
                ->exists();

            if ($exists) {
                $skipped++;
                $this->line(sprintf('  [SKIP] Notification already exists for appointment %s', $appointment->id));
                continue;
            }

            $title = match ($appointment->status) {
                'checked_in' => 'Patient has arrived',
                'waiting_triage' => 'Patient waiting for triage',
                'waiting_provider' => 'Patient ready for consultation',
                default => 'Patient waiting',
            };

            $this->line(sprintf(
                '  %s [user %s] %s — appointment %s',
                $dryRun ? '[DRY]' : '[CREATE]',
                $appointment->clinician_user_id,
                $title,
                $appointment->id,
            ));

            if (! $dryRun) {
                $dispatch->handle(
                    userId: $appointment->clinician_user_id,
                    category: 'clinical',
                    priority: 'normal',
                    title: $title,
                    body: sprintf('Patient #%s is waiting for you.', $appointment->patient_id),
                    actionUrl: sprintf('/clinician/queue?focusAppointmentId=%s', $appointment->id),
                    actionLabel: 'View queue',
                    contextType: 'appointment',
                    contextId: $appointment->id,
                );
            }

            $created++;
        }

        $this->info(sprintf('Done. Created: %d, Skipped (already notified): %d', $created, $skipped));

        return 0;
    }
}
