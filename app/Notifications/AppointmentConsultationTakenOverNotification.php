<?php

namespace App\Notifications;

use App\Support\Branding\SystemBrandingManager;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentConsultationTakenOverNotification extends Notification
{
    public function __construct(
        private readonly string $appointmentId,
        private readonly string $appointmentNumber,
        private readonly string $newOwnerName,
        private readonly ?string $takeoverReason,
        private readonly CarbonInterface $takenOverAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var SystemBrandingManager $brandingManager */
        $brandingManager = app(SystemBrandingManager::class);
        $applicationName = $brandingManager->systemName();
        $timestamp = $this->takenOverAt
            ->copy()
            ->timezone(config('app.timezone', 'UTC'))
            ->format('d M Y, H:i');

        $message = $brandingManager->applyNotificationBranding(new MailMessage)
            ->subject('Consultation taken over')
            ->greeting($this->greetingFor($notifiable))
            ->line("The active consultation for appointment {$this->appointmentNumber} was taken over by {$this->newOwnerName}.")
            ->line("Recorded at {$timestamp}.")
            ->action('Open appointments queue', $this->appointmentsUrl())
            ->line("This notice was sent by {$applicationName} so you can see that ownership changed while you were away.");

        if ($this->takeoverReason !== null) {
            $message->line("Handoff reason: {$this->takeoverReason}");
        }

        return $message;
    }

    private function appointmentsUrl(): string
    {
        return url('/appointments?focusAppointmentId='.urlencode($this->appointmentId).'&detailsTab=workflow');
    }

    private function greetingFor(object $notifiable): string
    {
        $name = trim((string) data_get($notifiable, 'name', ''));

        return $name !== '' ? "Hello {$name}" : 'Hello';
    }
}
