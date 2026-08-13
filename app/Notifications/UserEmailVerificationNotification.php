<?php

namespace App\Notifications;

use App\Support\Branding\SystemBrandingManager;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class UserEmailVerificationNotification extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        /** @var SystemBrandingManager $brandingManager */
        $brandingManager = app(SystemBrandingManager::class);
        $applicationName = $brandingManager->systemName();
        $expiresInMinutes = (int) config('auth.verification.expire', 60);

        return $brandingManager->applyNotificationBranding(new MailMessage)
            ->subject('Verify your email address')
            ->greeting($this->greetingFor($notifiable))
            ->line("Welcome to {$applicationName}.")
            ->line('Please confirm this email address before using protected workspaces.')
            ->action('Verify Email Address', $verificationUrl)
            ->line("This secure link will expire in {$expiresInMinutes} minutes.")
            ->line('If you were not expecting this account, you can ignore this email.');
    }

    private function greetingFor(mixed $notifiable): string
    {
        $name = trim((string) data_get($notifiable, 'name', ''));

        return $name !== '' ? "Hello {$name}" : 'Hello';
    }
}
