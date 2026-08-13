<?php

namespace App\Notifications;

use App\Support\Branding\SystemBrandingManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class UserCredentialLinkNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        return $this->isFirstTimeSetup($notifiable)
            ? $this->buildFirstTimeSetupMessage($notifiable)
            : $this->buildPasswordResetMessage($notifiable);
    }

    private function isFirstTimeSetup(mixed $notifiable): bool
    {
        return blank(data_get($notifiable, 'email_verified_at'));
    }

    private function buildFirstTimeSetupMessage(mixed $notifiable): MailMessage
    {
        /** @var SystemBrandingManager $brandingManager */
        $brandingManager = app(SystemBrandingManager::class);
        $applicationName = $brandingManager->systemName();
        $expiresInMinutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return $brandingManager->applyNotificationBranding(new MailMessage)
            ->subject('Set your password')
            ->greeting($this->greetingFor($notifiable))
            ->line("An account has been prepared for you in {$applicationName}.")
            ->line('Use the secure link below to set your password and confirm control of this email address.')
            ->action('Set Password', $this->resetUrl($notifiable))
            ->line("This secure link will expire in {$expiresInMinutes} minutes.")
            ->line('If you were not expecting this invite, you can ignore this email.');
    }

    private function buildPasswordResetMessage(mixed $notifiable): MailMessage
    {
        /** @var SystemBrandingManager $brandingManager */
        $brandingManager = app(SystemBrandingManager::class);
        $expiresInMinutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return $brandingManager->applyNotificationBranding(new MailMessage)
            ->subject('Reset your password')
            ->greeting($this->greetingFor($notifiable))
            ->line('A password reset was requested for your account.')
            ->action('Reset Password', $this->resetUrl($notifiable))
            ->line("This secure link will expire in {$expiresInMinutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }

    private function greetingFor(mixed $notifiable): string
    {
        $name = trim((string) data_get($notifiable, 'name', ''));

        return $name !== '' ? "Hello {$name}" : 'Hello';
    }
}
