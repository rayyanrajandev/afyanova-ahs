<?php

namespace App\Notifications;

use App\Support\Branding\SystemBrandingManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InventoryExpiryAlertNotification extends Notification
{
    public function __construct(
        private readonly int $expiredCount,
        private readonly int $criticalCount,
        private readonly int $warningCount,
        private readonly int $quarantinedCount,
        private readonly array $alertItems,
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
        $date = now()->timezone(config('app.timezone', 'UTC'))->format('d M Y');

        $message = $brandingManager->applyNotificationBranding(new MailMessage)
            ->subject("Inventory Expiry Alert — {$date}")
            ->greeting('Inventory Expiry Alert')
            ->line("The following stock expiry issues require attention:");

        if ($this->expiredCount > 0) {
            $message->line("**{$this->expiredCount} batch(es) have expired.**");
        }
        if ($this->criticalCount > 0) {
            $message->line("**{$this->criticalCount} batch(es) are expiring within 30 days.**");
        }
        if ($this->warningCount > 0) {
            $message->line("{$this->warningCount} batch(es) are expiring within 90 days.");
        }
        if ($this->quarantinedCount > 0) {
            $message->line("{$this->quarantinedCount} expired batch(es) were automatically quarantined.");
        }

        // Add top 10 most urgent items
        $urgent = array_slice(
            array_filter($this->alertItems, fn (array $i) => $i['level'] !== 'warning'),
            0,
            10
        );

        if (count($urgent) > 0) {
            $message->line('---');
            $message->line('**Most urgent items:**');
            foreach ($urgent as $item) {
                $label = strtoupper($item['level']);
                $days = isset($item['days_until_expiry'])
                    ? " ({$item['days_until_expiry']} days left)"
                    : ' (EXPIRED)';
                $message->line("• [{$label}] {$item['item_name']} — Batch {$item['batch_number']}, Qty {$item['quantity']}{$days}");
            }
        }

        $message->action('Open Inventory', url('/inventory-procurement'))
            ->line("This automated alert was sent by {$applicationName}.");

        return $message;
    }
}
