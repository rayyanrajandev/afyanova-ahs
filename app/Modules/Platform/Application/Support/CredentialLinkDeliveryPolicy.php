<?php

namespace App\Modules\Platform\Application\Support;

class CredentialLinkDeliveryPolicy
{
    public function defaultMailer(): string
    {
        return (string) config('mail.default', 'log');
    }

    public function shouldReturnLocalPreview(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        if (app()->environment('local')) {
            return true;
        }

        return app()->environment('testing') && $this->isLogOnlyMailer();
    }

    public function deliversExternally(): bool
    {
        return ! $this->shouldReturnLocalPreview() && ! $this->isLogOnlyMailer();
    }

    public function warning(): ?string
    {
        if ($this->shouldReturnLocalPreview()) {
            return 'Invite and password-reset links are generated as local preview links in this environment.';
        }

        if ($this->isLogOnlyMailer()) {
            return 'Invite and password-reset emails are currently written to the application log instead of being delivered to a mailbox.';
        }

        return null;
    }

    private function isLogOnlyMailer(): bool
    {
        return in_array($this->defaultMailer(), ['log', 'array'], true);
    }
}
