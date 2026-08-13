<?php

namespace App\Notifications;

use App\Support\Branding\SystemBrandingManager;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MedicalRecordHandoffNotification extends Notification implements ShouldQueue
{
    public function __construct(
        private readonly string $medicalRecordId,
        private readonly string $recordNumber,
        private readonly string $initiatorName,
        private readonly ?string $handoffNote,
        private readonly CarbonInterface $handedOffAt,
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
        $timestamp = $this->handedOffAt
            ->copy()
            ->timezone(config('app.timezone', 'UTC'))
            ->format('d M Y, H:i');

        $message = $brandingManager->applyNotificationBranding(new MailMessage)
            ->subject('Clinical note handed off to you')
            ->greeting($this->greetingFor($notifiable))
            ->line("{$this->initiatorName} has handed off clinical note {$this->recordNumber} to you.")
            ->line("Handed off at {$timestamp}.")
            ->action('Open note', $this->noteUrl())
            ->line("Please review and continue the note. The handoff will be locked to you once accepted.")
            ->line("This notice was sent by {$applicationName}.");

        if ($this->handoffNote !== null) {
            $message->line("Handoff note: {$this->handoffNote}");
        }

        return $message;
    }

    private function noteUrl(): string
    {
        return url('/medical-records/'.$this->medicalRecordId);
    }

    private function greetingFor(object $notifiable): string
    {
        $name = trim((string) data_get($notifiable, 'name', ''));

        return $name !== '' ? "Hello {$name}" : 'Hello';
    }
}
